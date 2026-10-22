<?php
declare(strict_types=1);

namespace Zara\Controller;

use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * Lazily creates controller instances.
 *
 * @package Zara\Core\Http\Controllers
 */
final class ControllerFactory implements ControllerFactoryInterface
{
    public function __construct(private readonly ?ContainerInterface $container = null) {}

    /**
     * Resolves and creates a controller from its class name.
     *
     * @param class-string $controllerClass
     * @throws RuntimeException
     */
    public function make(string $controllerClass): object
    {
        if (!class_exists($controllerClass)) {
            throw new RuntimeException(
                sprintf("Controller class [%s] was not found.", $controllerClass)
            );
        }

        // Let Zara's container handle dependency injection when it knows the class.
        if ($this->container?->has($controllerClass) === true) {
            return $this->container->get($controllerClass);
        }

        // Otherwise, instantiate directly (the constructor must not require arguments).
        try {
            return new $controllerClass();
        } catch (Throwable $e) {
            throw new RuntimeException(
                sprintf(
                    "Unable to instantiate controller [%s]. " .
                    "Check its dependencies or register it in the container.",
                    $controllerClass
                ),
                0,
                $e
            );
        }
    }
}
