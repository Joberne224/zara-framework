<?php
declare(strict_types=1);

namespace Zara\Container\Interfaces;

interface MethodInjectorInterface
{
    public function invoke(callable|array $callable, array $routeParams = []): mixed;
}