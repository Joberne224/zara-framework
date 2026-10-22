<?php
declare(strict_types=1);

namespace Zara\Container\Compiler;

use Zara\Container\ServiceRegistry;
use Zara\Container\ParameterBag;
use Zara\Container\ReflectionCache;
use Zara\Container\Exceptions\ContainerException;

final class ServiceCodeGenerator
{
    /** @var array<string, true> IDs discovered during the last generate() call */
    private array $lastDependencies = [];

    public function __construct(
        private ServiceRegistry $registry,
        private ParameterBag $parameters,
        private ReflectionCache $refCache,
    ) {}

    /**
     * @return list<string> IDs of services referenced (through $this->get())
     *         by the last generate() call. Used to build the dependency graph
     *         between compilable services and detect cycles or non-compilable dependencies.
     */
    public function getLastDependencies(): array
    {
        return array_keys($this->lastDependencies);
    }

    /**
     * Generates a PHP method body to construct $id directly, without
     * Reflection. Throws ContainerException if the definition cannot be
     * translated statically; the compiler then excludes this service instead
     * of failing completely.
     */
    public function generate(string $id, array $definition): string
    {
        $this->lastDependencies = [];

        $callable = $definition['callable'];

        if (!is_string($callable) || !class_exists($callable) || str_contains($callable, '::')) {
            throw new ContainerException("Not statically compilable: callable is not a plain class name.");
        }

        // These paths are not equivalent to the dynamic factory yet.
        if (($definition['calls'] ?? []) !== []) {
            throw new ContainerException("Not statically compilable: configured calls require dynamic argument resolution.");
        }
        $reflection = new \ReflectionClass($callable);
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->getAttributes(\Zara\Container\Attributes\Inject::class) !== []) {
                throw new ContainerException("Not statically compilable: #[Inject] requires dynamic resolution.");
            }
        }
        foreach ($reflection->getProperties() as $property) {
            if ($property->getAttributes(\Zara\Container\Attributes\Inject::class) !== []) {
                throw new ContainerException("Not statically compilable: property #[Inject] requires dynamic resolution.");
            }
        }

        $class = $callable;
        $params = $this->refCache->getConstructorParams($class);
        $explicitArgs = $this->normalizeArguments($definition['arguments'] ?? []);
        $autowire = (bool)($definition['autowire'] ?? true);

        $argExpressions = [];
        foreach ($params as $param) {
            $argExpressions[] = $this->resolveParamExpression($param, $explicitArgs, $autowire, $class);
        }

        $ctorCall = $argExpressions === []
            ? "new \\$class()"
            : "new \\$class(\n            " . implode(",\n            ", $argExpressions) . "\n        )";

        $lines = ["\$instance = $ctorCall;"];

        foreach ($definition['calls'] ?? [] as $call) {
            $lines[] = $this->generateCallExpression($call, $definition, $id);
        }

        $lines[] = 'return $instance;';

        return implode("\n        ", $lines);
    }

    private function normalizeArguments(array $arguments): array
    {
        // YAML accepts both numeric positions and parameter names; mirror the
        // same dual-key lookup used by ParameterResolver.
        return $arguments;
    }

    private function resolveParamExpression(
        \ReflectionParameter $param,
        array $explicitArgs,
        bool $autowire,
        string $class,
    ): string {
        $position = $param->getPosition();
        $name = $param->getName();

        if (array_key_exists($position, $explicitArgs)) {
            return $this->valueToExpression($explicitArgs[$position]);
        }
        if (array_key_exists($name, $explicitArgs)) {
            return $this->valueToExpression($explicitArgs[$name]);
        }

        if ($autowire) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();
                if ($this->registry->hasDefinition($typeName) || $this->registry->isAlias($typeName)) {
                    // For another compiled service, call its generated method
                    // instead of rebuilding it inline, preserving singleton sharing.
                    $this->lastDependencies[$typeName] = true;
                    return "\$this->get('$typeName')";
                }
                if (!$param->isDefaultValueAvailable() && class_exists($typeName)) {
                    throw new ContainerException(
                        "Not statically compilable: implicit autowiring of undeclared class '$typeName' " .
                        "for parameter \${$name} of '$class' cannot be resolved at compile time."
                    );
                }
            }
        }

        if ($param->isDefaultValueAvailable()) {
            return $this->valueToExpression($param->getDefaultValue());
        }

        throw new ContainerException("Not statically compilable: parameter \${$name} of '$class' has no resolvable source.");
    }

    /**
     * Converts an argument value (string '@service', '%param%', scalar, or
     * array) into a PHP expression to write into the compiled file.
     */
    private function valueToExpression(mixed $value): string
    {
        if (is_string($value)) {
            if (str_starts_with($value, '@')) {
                $serviceId = substr($value, 1);
                $this->lastDependencies[$serviceId] = true;
                return "\$this->get(" . var_export($serviceId, true) . ")";
            }
            if (str_starts_with($value, '!tagged:')) {
                throw new ContainerException("Not statically compilable: tagged references are resolved dynamically.");
            }
            if (str_contains($value, '%')) {
                // Resolve parameters (including %env(...)%) when the compiled
                // file runs; never bake them into generated code, or an
                // environment secret could end up in a versioned PHP file.
                if (!(str_starts_with($value, '%') && str_ends_with($value, '%') && substr_count($value, '%') === 2)) {
                    throw new ContainerException("Not statically compilable: mixed parameter interpolation requires dynamic resolution.");
                }
                return "\$this->parameters->get(" . var_export($this->stripPercent($value), true) . ")";
            }
            return var_export($value, true);
        }

        if (is_array($value)) {
            $items = array_map(fn($v) => $this->valueToExpression($v), $value);
            return '[' . implode(', ', $items) . ']';
        }

        return var_export($value, true);
    }

    private function stripPercent(string $value): string
    {
        return (str_starts_with($value, '%') && str_ends_with($value, '%') && substr_count($value, '%') === 2)
            ? substr($value, 1, -1)
            : $value; // Mixed string: left intact and handled by ParameterBag.
    }

    private function generateCallExpression(array $call, array $definition, string $id): string
    {
        $method = $call[0];
        $args = array_map(fn($v) => $this->valueToExpression($v), $call[1] ?? []);
        return "\$instance->$method(" . implode(', ', $args) . ");";
    }
}
