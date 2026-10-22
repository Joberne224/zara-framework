<?php

declare(strict_types=1);

namespace Zara\Core\Routing\Interfaces;

use Zara\Core\Routing\Interfaces\RouteMatchInterface;

/**
 * Contract for matching HTTP methods and URIs against registered routes.
 */
interface RouteMatcherInterface
{
    /**
     * Matches an HTTP method and URI against a route collection.
     *
     * @param array<string, list<array{route: mixed, callable: string|array|callable}>> $routes
     */
    public function matchPath(
        array $routes,
        string $method,
        string $uri,
        string $defaultNamespace
    ): RouteMatchInterface;
}