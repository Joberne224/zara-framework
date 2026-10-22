<?php
declare(strict_types=1);

namespace Zara\Container;

use ReflectionFunction;
use ReflectionMethod;
use Zara\Container\Factory\ArgumentResolver;
use Zara\Container\Factory\ParameterResolver;
use Zara\Container\Interfaces\MethodInjectorInterface;
use Zara\Container\Exceptions\ParameterResolutionException;
use Zara\Container\Exceptions\ContainerException;

final class MethodInjector implements MethodInjectorInterface
{
    private ParameterResolver $parameterResolver;

    public function __construct(
        private Container $container,
    ) {
        // ServiceRegistry/ParameterBag cannot be autowired constructor
        // parameters here: they are RESERVED IDs (protected by
        // ServiceRegistry::assertNotReserved()), so $container->has() throws
        // when autowiring tries to resolve them by type. Container itself is
        // in SELF_IDS (not RESERVED_IDS) and always resolves, so use its public
        // API instead of separate constructor parameters.
        $registry = $this->container->getRegistry();
        $parameters = $this->container->getParameterBag();

        // Reuses the same resolution engine as constructor injection instead
        // of duplicating it — eliminates a whole class of bugs (e.g. the
        // union-type crash fixed earlier) and keeps future ArgumentResolver/
        // ParameterResolver improvements automatically available here too.
        $argumentResolver = new ArgumentResolver($this->container, $registry, $parameters);
        $this->parameterResolver = new ParameterResolver($this->container, $argumentResolver);
    }

    public function invoke(callable|array $callable, array $routeParams = []): mixed
    {
        if (is_array($callable)) {
            [$classOrObject, $method] = $callable;
            $object = is_string($classOrObject) ? $this->container->get($classOrObject) : $classOrObject;
            $reflection = new ReflectionMethod($object, $method);
            $args = $this->resolveMethodArgs($reflection, $routeParams);
            return $reflection->invokeArgs($object, $args);
        }

        $reflection = new ReflectionFunction($callable(...));
        $args = $this->resolveMethodArgs($reflection, $routeParams);
        return $reflection->invokeArgs($args);
    }

    private function resolveMethodArgs(
        ReflectionMethod|ReflectionFunction $reflection,
        array $routeParams
    ): array {
        try {
            // $routeParams doubles as $explicitArgs: ParameterResolver checks
            // position first, then name — route params are keyed by name, so
            // this preserves the original priority (route param wins over
            // autowiring, which wins over the constructor's default value).
            return $this->parameterResolver->resolveParameters(
                $reflection->getParameters(),
                $routeParams,
                autowire: true,
                errorContext: $this->describeTarget($reflection),
            );
        } catch (ContainerException $e) {
            // Preserves the richer exception type expected by callers
            // (Dispatcher/ControllerFactory), instead of leaking a generic
            // ContainerException where ParameterResolutionException was
            // the established contract.
            throw ParameterResolutionException::fromContainerException($reflection, $e);
        }
    }

    private function describeTarget(ReflectionMethod|ReflectionFunction $reflection): string
    {
        return $reflection instanceof ReflectionMethod
            ? "for {$reflection->getDeclaringClass()->getName()}::{$reflection->getName()}()"
            : "for closure {$reflection->getName()}";
    }
}
