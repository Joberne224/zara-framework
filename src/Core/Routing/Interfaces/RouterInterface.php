<?php

declare(strict_types=1);

namespace Zara\Core\Routing\Interfaces;

use Closure;
use Zara\Core\Http\Interfaces\RequestInterface;

/**
 * Contract for HTTP routing, route registration, matching, and URL generation.
 */
interface RouterInterface
{
    // -------------------------------------------------------------------------
    // Route Registration
    // -------------------------------------------------------------------------

    public function get(string $path, callable|array|string $callable): RouteInterface;

    public function post(string $path, callable|array|string $callable): RouteInterface;

    public function put(string $path, callable|array|string $callable): RouteInterface;

    public function delete(string $path, callable|array|string $callable): RouteInterface;

    public function patch(string $path, callable|array|string $callable): RouteInterface;

    public function options(string $path, callable|array|string $callable): RouteInterface;

    public function any(string $path, callable|array|string $callable): void;

    public function group(array $options, Closure $callback): void;

    // -------------------------------------------------------------------------
    // Middleware & Configuration
    // -------------------------------------------------------------------------

    public function addGlobalMiddleware(string $middlewareClass): static;

    /**
     * @return list<string>
     */
    public function getGlobalMiddlewares(): array;

    public function setDefaultNamespace(string $namespace): static;

    /**
     * @return list<array{method: string, path: string, handler: string, middlewares: list<string>}>
     */
    public function getRoutes(): array;

    // -------------------------------------------------------------------------
    // Route Loading & Cache Management
    // -------------------------------------------------------------------------

    public function load(string $routesFile): void;

    public function compile(): void;

    public function clear(): void;

    public function hasCache(): bool;

    public function isCached(): bool;

    public function getCachePath(): ?string;

    // -------------------------------------------------------------------------
    // URL Generation & Matching
    // -------------------------------------------------------------------------

    /**
     * Generates a URL for a named route with parameters.
     *
     * @param array<string, scalar> $params
     */
    public function route(string $name, array $params = []): string;

    public function match(RequestInterface $request): RouteMatchInterface;

    public function matchPath(string $method, string $uri): RouteMatchInterface;
}