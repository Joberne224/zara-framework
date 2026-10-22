<?php
declare(strict_types=1);

namespace Zara\Container\Factory;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionUnionType;
use ReflectionProperty;
use Zara\Container\Attributes\Inject;
use Psr\Container\ContainerInterface;
use Zara\Container\Exceptions\ContainerException;

/**
 * Injects properties marked with #[Inject] after a service is instantiated.
 * Kept separate from ServiceInstantiator (SRP): instantiation creates the
 * object, then property injection completes it. These responsibilities can
 * evolve independently (e.g. caching annotated properties through ReflectionCache).
 *
 * Promoted constructor properties (`public function __construct(
 * private LoggerInterface $logger)`) are intentionally skipped: their
 * #[Inject] attribute is already read and applied by ParameterResolver during
 * construction. Processing them again would resolve twice and cause a PHP
 * Error for promoted readonly properties ("already initialized").
 */
final class PropertyInjector
{
    public function __construct(
        private ContainerInterface $container,
        private ArgumentResolver   $argumentResolver,
    ) {}

    public function inject(object $instance): void
    {
        $reflection = new ReflectionClass($instance);

        foreach ($reflection->getProperties() as $property) {
            if ($property->isPromoted() || $property->isStatic()) {
                continue;
            }

            $attributes = $property->getAttributes(Inject::class);
            if ($attributes === []) {
                continue;
            }

            /** @var Inject $inject */
            $inject = $attributes[0]->newInstance();
            $context = "for property \${$property->getName()} of '{$reflection->getName()}'";

            $value = $inject->value !== null
                ? $this->argumentResolver->resolveArgument($inject->value, $property->getName(), $context)
                : $this->resolveByDeclaredType($property, $context);

            $this->assign($property, $instance, $value, $context);
        }
    }

    /**
     * A bare #[Inject] on a property is the only way to enable property
     * autowiring, since properties are never resolved implicitly, unlike parameters.
     */
    private function resolveByDeclaredType(ReflectionProperty $property, string $context): mixed
    {
        $type = $property->getType();
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

            if ($this->container->has($typeName)) {
                return $this->argumentResolver->resolveSingleService($typeName);
            }
            if (class_exists($typeName)) {
                return $this->argumentResolver->resolveSingleService($typeName);
            }
        }

        throw new ContainerException(
            "Container Error: #[Inject] $context has no explicit value and no autowirable type could be " .
            "resolved (missing type-hint, built-in type, or unregistered/non-existent class)."
        );
    }

    private function assign(ReflectionProperty $property, object $instance, mixed $value, string $context): void
    {
        try {
            $property->setValue($instance, $value);
        } catch (\Error $e) {
            // Raise a clear error instead of leaking a vague PHP Error. Typical
            // causes are a readonly property already initialized by the
            // constructor or a value incompatible with the property type.
            // PHP only allows reflection to initialize a non-promoted readonly
            // property from the declaring class scope. If that is the cause,
            // remove `readonly` or use constructor injection.
            throw new ContainerException(
                "Container Error: Unable to assign #[Inject] value $context: {$e->getMessage()}"
            );
        }
    }
}
