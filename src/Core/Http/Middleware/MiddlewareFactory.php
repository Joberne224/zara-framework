<?php
declare(strict_types=1);

namespace Zara\Core\Http\Middleware;

use Psr\Container\ContainerInterface;
use Zara\Core\Http\Interfaces\MiddlewareInterface;
use RuntimeException;
use Throwable;

/**
 * Builds middleware instances from their class names at dispatch time.
 */
final class MiddlewareFactory
{
    public function __construct(private readonly ?ContainerInterface $container = null) {}

    /**
     * @param class-string<MiddlewareInterface> $middlewareClass
     */
    public function make(string $middlewareClass): MiddlewareInterface
    {
        if (!class_exists($middlewareClass)) {
            throw new RuntimeException("La classe middleware '{$middlewareClass}' est introuvable.");
        }

        $middleware = $this->container?->has($middlewareClass) === true
            ? $this->container->get($middlewareClass)
            : $this->instantiate($middlewareClass);

        if (!$middleware instanceof MiddlewareInterface) {
            throw new \RuntimeException(
                "La classe middleware '{$middlewareClass}' doit implémenter MiddlewareInterface."
            );
        }

        return $middleware;
    }

    /**
     * @param list<class-string<MiddlewareInterface>> $middlewareClasses
     * @return list<MiddlewareInterface>
     */
    public function makeMany(array $middlewareClasses): array
    {
        return array_map(
            fn (string $middlewareClass): MiddlewareInterface => $this->make($middlewareClass),
            $middlewareClasses
        );
    }

    private function instantiate(string $middlewareClass): object
    {
        try {
            return new $middlewareClass();
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Cannot instantiate middleware '{$middlewareClass}'. " .
                "Register it as a service if its constructor has dependencies.",
                0,
                $e
            );
        }
    }
}