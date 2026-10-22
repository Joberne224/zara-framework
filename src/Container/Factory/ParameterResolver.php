<?php
declare(strict_types=1);

namespace Zara\Container\Factory;

use ReflectionParameter;
use ReflectionNamedType;
use ReflectionUnionType;
use Psr\Container\ContainerInterface;
use Zara\Container\Attributes\Inject;
use Zara\Container\Exceptions\ContainerException;

final class ParameterResolver
{
    /**
     * Internal sentinel distinct from `null`: `null` is a valid return value
     * for "no explicit value on #[Inject]" (bare Inject), so a separate marker
     * is needed for "no result found".
     */
    private const NOT_FOUND = "\0__ZARA_PARAM_NOT_FOUND__\0";

    public function __construct(
        private ContainerInterface $container,
        private ArgumentResolver   $argumentResolver,
    ) {}

    /**
     * @param array<ReflectionParameter> $params
     *
     * Resolution priority, highest to lowest:
     *   1. Explicit argument from the service file (YAML), by position,
     *      then by name without a prefix ('host': ...), then with a '$' prefix
     *      ('$host': ...). Both forms are accepted; the unprefixed name wins
     *      if both are present;
     *   2. #[Inject] on the parameter, even when autowiring is disabled: an
     *      attribute is an explicit code directive, not an implicit assumption;
     *   3. implicit autowiring by declared type;
     *   4. parameter default value.
     */
    public function resolveParameters(array $params, array $explicitArgs, bool $autowire, string $errorContext): array
    {
        $resolved = [];

        foreach ($params as $param) {
            $name = $param->getName();
            $position = $param->getPosition();
            $dollarName = '$' . $name;

            // 1a. Position-indexed argument (e.g. 0, 1, 2).
            if (array_key_exists($position, $explicitArgs)) {
                $resolved[] = $explicitArgs[$position];
                continue;
            }

            // 1b. Named argument without a $ prefix (e.g. host: 'smtp.gmail.com').
            if (array_key_exists($name, $explicitArgs)) {
                $resolved[] = $explicitArgs[$name];
                continue;
            }

            // 1c. Named argument with a $ prefix (e.g. $host: 'smtp.gmail.com'),
            // an alternative matching the parameter name in the PHP signature.
            if (array_key_exists($dollarName, $explicitArgs)) {
                $resolved[] = $explicitArgs[$dollarName];
                continue;
            }

            $injectValue = $this->readInjectAttribute($param);
            if ($injectValue !== self::NOT_FOUND) {
                $resolved[] = $this->resolveFromInjectAttribute($param, $injectValue, $errorContext);
                continue;
            }

            if ($autowire) {
                $byType = $this->resolveParamByDeclaredType($param, forceOverDefault: false);
                if ($byType !== self::NOT_FOUND) {
                    $resolved[] = $byType;
                    continue;
                }
            }

            if ($param->isDefaultValueAvailable()) {
                $resolved[] = $param->getDefaultValue();
                continue;
            }

            $hint = $autowire
                ? "Define it as a service, add #[Inject], or provide a default value."
                : "Autowiring is disabled for this service. Provide an explicit argument, #[Inject], or a default value.";

            throw new ContainerException(
                "Container Error: Cannot resolve parameter '\${$name}' $errorContext. $hint"
            );
        }

        return $resolved;
    }

    /**
     * Reads a parameter's #[Inject] attribute, if present. Returns
     * self::NOT_FOUND if it is absent, or the attribute value (possibly null
     * for a bare #[Inject]) otherwise.
     */
    private function readInjectAttribute(ReflectionParameter $param): mixed
    {
        $attributes = $param->getAttributes(Inject::class);
        if ($attributes === []) {
            return self::NOT_FOUND;
        }

        /** @var Inject $inject */
        $inject = $attributes[0]->newInstance();
        return $inject->value;
    }

    /**
     * Resolves a parameter annotated with #[Inject]. With an explicit value,
     * delegates to ArgumentResolver using the same syntax as YAML. A bare
     * #[Inject] forces resolution by declared type even when a default exists,
     * and fails clearly if no autowirable type is found rather than silently
     * falling back to the default (the attribute expresses explicit intent).
     */
    private function resolveFromInjectAttribute(ReflectionParameter $param, ?string $injectValue, string $errorContext): mixed
    {
        $name = $param->getName();

        if ($injectValue !== null) {
            return $this->argumentResolver->resolveArgument(
                $injectValue,
                $name,
                "$errorContext (via #[Inject] on \${$name})",
            );
        }

        $byType = $this->resolveParamByDeclaredType($param, forceOverDefault: true);
        if ($byType !== self::NOT_FOUND) {
            return $byType;
        }

        throw new ContainerException(
            "Container Error: #[Inject] on parameter '\${$name}' $errorContext has no explicit value and no " .
            "autowirable type could be resolved (missing type-hint, built-in type, or unregistered/non-existent class)."
        );
    }

    /**
     * Attempts to resolve a parameter by its declared type (autowiring).
     * $forceOverDefault lets a bare #[Inject] override a default that would
     * normally take precedence during implicit autowiring. Returns
     * self::NOT_FOUND if no candidate could be resolved.
     */
    private function resolveParamByDeclaredType(ReflectionParameter $param, bool $forceOverDefault): mixed
    {
        $type = $param->getType();
        $candidates = match (true) {
            $type instanceof ReflectionNamedType => [$type],
            $type instanceof ReflectionUnionType => array_filter(
                $type->getTypes(),
                static fn ($t) => $t instanceof ReflectionNamedType,
            ),
            default => [],
        };

        foreach ($candidates as $namedType) {
            if ($namedType->isBuiltin()) {
                continue;
            }
            $typeName = $namedType->getName();

            // An explicitly registered service takes precedence, even when
            // the parameter has a default value.
            if ($this->container->has($typeName)) {
                return $this->argumentResolver->resolveSingleService($typeName);
            }

            // If no service is registered, the default takes precedence over
            // implicit class autowiring unless $forceOverDefault is set (bare #[Inject]).
            if (($forceOverDefault || !$param->isDefaultValueAvailable()) && class_exists($typeName)) {
                return $this->argumentResolver->resolveSingleService($typeName);
            }
        }

        return self::NOT_FOUND;
    }
}
