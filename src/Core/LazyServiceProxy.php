<?php
declare(strict_types=1);

namespace Zara\Core;

use Psr\Container\ContainerInterface;
use \Closure;

class LazyServiceProxy implements LazyServiceProxyInterface
{
    private ContainerInterface $container;
    private string $serviceId;
    private ?object $realInstance = null;
    private Closure $factory;
    private bool $isResolving = false;

    public function __construct(ContainerInterface $container, string $serviceId, Closure $factory)
    {
        $this->container = $container;
        $this->serviceId = $serviceId;
        $this->factory = $factory;
    }

    public function getRealInstance(): object
    {
        if ($this->realInstance !== null) {
            return $this->realInstance;
        }

        if ($this->isResolving) {
            throw new \RuntimeException(
                "Circular dependency detected while resolving lazy reference '{$this->serviceId}'. " .
                "The service is already being instantiated. " .
                "Possible fixes:\n" .
                "  1. Break the cycle by injecting the Container into the dependent " .
                "service and calling \$container->get('{$this->serviceId}') lazily.\n" .
                "  2. Restructure the dependency graph so the cycle no longer exists."
            );
        }

        $this->isResolving = true;
        try {
            $factory = $this->factory;
            $this->realInstance = $factory($this->container, $this->serviceId);
        } finally {
            $this->isResolving = false;
        }

        return $this->realInstance;
    }

    public function __call(string $method, array $args)
    {
        $instance = $this->getRealInstance();
        if (!method_exists($instance, $method)) {
            throw new \RuntimeException("Method '$method' does not exist on service '{$this->serviceId}'");
        }
        return $instance->$method(...$args);
    }
}