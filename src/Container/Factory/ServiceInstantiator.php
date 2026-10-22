<?php

declare(strict_types=1);

namespace Zara\Container\Factory;

use Zara\Container\ReflectionCache;
use Psr\Container\ContainerInterface;
use Zara\Container\Exceptions\ContainerException;

final class ServiceInstantiator
{
    public function __construct(
        private ContainerInterface $container,
        private ReflectionCache    $refCache,
        private ArgumentResolver   $argumentResolver,
        private ParameterResolver  $parameterResolver,
        private PropertyInjector   $propertyInjector,
    ) {}

    public function createInstance(string $id, array $def): object
    {
        $callable = $def['callable'];
        $arguments = $def['arguments'] ?? [];
        $autowire = (bool)($def['autowire'] ?? true);

        $instance = $this->build($id, $callable, $arguments, $autowire);

        // Centralized here instead of duplicated across each creation strategy:
        // property #[Inject] must apply to the final instance regardless of
        // how it was created (autowiring, static method, instance factory, callable).
        $this->propertyInjector->inject($instance);

        return $instance;
    }

    private function build(string $id, mixed $callable, array $arguments, bool $autowire): object
    {
        if (is_string($callable) && str_contains($callable, '::')) {
            return $this->createFromStaticMethod($id, $callable, $arguments, $autowire);
        }

        if (is_string($callable) && class_exists($callable)) {
            return $this->autowire($callable, $arguments, $autowire);
        }

        if (is_array($callable) && count($callable) === 2
            && is_string($callable[0]) && str_starts_with($callable[0], '@')
            && is_string($callable[1])
        ) {
            return $this->createFromInstanceMethod($id, $callable, $arguments, $autowire);
        }

        if (is_callable($callable)) {
            return $this->createFromRawCallable($callable, $arguments, $autowire);
        }

        throw new ContainerException("Container Error: Invalid callable or class name for service '$id'");
    }

    /**
     * $callable always receives $this->container as its first positional
     * argument (the historical convention for this path). Remaining
     * parameters go through ParameterResolver so #[Inject] and named
     * arguments work here too, not only raw YAML positions.
     */
    private function createFromRawCallable(callable $callable, array $arguments, bool $autowire): mixed
    {
        $reflection = $this->reflectionForCallable($callable);
        $paramsAfterContainer = $reflection !== null
            ? array_slice($reflection->getParameters(), 1)
            : [];

        if ($paramsAfterContainer === []) {
            $resolved = $this->argumentResolver->resolveArguments($arguments);
            return call_user_func($callable, $this->container, ...$resolved);
        }

        // Reindexing: YAML positions (0, 1, 2...) refer to parameters AFTER
        // $container, while ReflectionParameter::getPosition() returns the
        // position in the full signature (one higher). Without this offset,
        // ParameterResolver would look up position 0 ($container) instead of 1.
        $shiftedArguments = [];
        foreach ($arguments as $key => $value) {
            $shiftedArguments[is_int($key) ? $key + 1 : $key] = $value;
        }

        $explicit = $this->argumentResolver->resolveArguments($shiftedArguments);
        $resolvedRest = $this->parameterResolver->resolveParameters(
            $paramsAfterContainer,
            $explicit,
            $autowire,
            'for callable service definition',
        );

        return call_user_func($callable, $this->container, ...$resolvedRest);
    }

    /**
     * PHP 8.1 first-class callable syntax ($callable(...)) works with any
     * callable form (string, array, Closure, invokable object), so there is no
     * need to distinguish cases using manual type-based reflection.
     */
    private function reflectionForCallable(callable $callable): ?\ReflectionFunctionAbstract
    {
        try {
            return new \ReflectionFunction($callable(...));
        } catch (\Throwable) {
            return null;
        }
    }

    private function autowire(string $class, array $argumentMappings, bool $autowire): object
    {
        $params = $this->refCache->getConstructorParams($class);
        if ($params === []) {
            return new $class();
        }

        $explicit = $this->argumentResolver->resolveArguments($argumentMappings, "in the constructor of '$class'");
        $args = $this->parameterResolver->resolveParameters($params, $explicit, $autowire, "for class '{$class}'");

        return new $class(...$args);
    }

    private function createFromStaticMethod(string $id, string $callable, array $arguments, bool $autowire): object
    {
        [$class, $method] = explode('::', $callable, 2);

        if (!method_exists($class, $method)) {
            throw new ContainerException("Container Error: Static method '$callable' not found for service '$id'");
        }

        $rm = new \ReflectionMethod($class, $method);
        $explicit = $this->argumentResolver->resolveArguments($arguments);
        $resolved = $this->parameterResolver->resolveParameters($rm->getParameters(), $explicit, $autowire, "for static method '$callable'");

        return $class::$method(...$resolved);
    }

    private function createFromInstanceMethod(string $id, array $callable, array $arguments, bool $autowire): object
    {
        [$serviceId, $method] = $callable;
        $instance = $this->argumentResolver->resolveSingleService(ltrim($serviceId, '@'));

        $explicit = $this->argumentResolver->resolveArguments($arguments, "in factory method call for service '$id'");
        $rm = new \ReflectionMethod($instance, $method);
        $args = $this->parameterResolver->resolveParameters($rm->getParameters(), $explicit, $autowire, "for method '" . get_class($instance) . "::{$method}' called by service '$id'");

        return $instance->{$method}(...$args);
    }
}
