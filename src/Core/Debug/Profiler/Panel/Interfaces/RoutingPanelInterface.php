<?php

declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Panel\Interfaces;

interface RoutingPanelInterface
{
    public function setMatchedRoute(
        string $path,
        callable|array|string $callable,
        array $params,
        array $middlewares
    ): void;
}