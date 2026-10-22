<?php

declare(strict_types=1);

namespace Zara\Controller;

interface ControllerFactoryInterface
{
    /**
     * Resolves and creates a controller from its class name.
     *
     * @param class-string $controllerClass
     */
    public function make(string $controllerClass): object;
}
