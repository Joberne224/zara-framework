<?php

declare(strict_types=1);

namespace Zara\Core\Routing\Interfaces;

use Closure;
use Zara\Core\Routing\Route;

/**
 * Contract for managing route definitions, groups, and global middlewares.
 */
interface RouteRegistryInterface
{
    public function get(string $path, callable|array|string $callable): RouteInterface;

    public function post(string $path, callable|array|string $callable): RouteInterface;

    public function put(string $path, callable|array|string $callable): RouteInterface;

    public function delete(string $path, callable|array|string $callable): RouteInterface;

    public function patch(string $path, callable|array|string $callable): RouteInterface;

    public function options(string $path, callable|array|string $callable): RouteInterface;

    public function any(string $path, callable|array|string $callable): void;

    public function group(array $options, Closure $callback, object $context): void;

    public function addGlobalMiddleware(string $middlewareClass): static;

    /**
     * @return list<string>
     */
    public function getGlobalMiddlewares(): array;

    public function setDefaultNamespace(string $namespace): static;

    public function getDefaultNamespace(): string;

    public function hasRoutes(): bool;

    /**
     * @return array<string, list<array{route: Route, callable: string|array|callable}>>
     */
    public function all(): array;

    /**
     * @param array<string, list<array{route: Route, callable: string|array|callable}>> $routes
     */
    public function setRoutes(array $routes): static;

    public function findByName(string $name): ?RouteInterface;

    /**
     * @return list<array{method: string, path: string, handler: string, middlewares: list<string>}>
     */
    public function getRoutes(): array;
}