<?php

declare(strict_types=1);

namespace Zara\Core\Routing\Internal;

use Closure;
use RuntimeException;
use Zara\Core\Routing\Interfaces\RouteInterface;
use Zara\Core\Routing\Interfaces\RouteRegistryInterface;
use Zara\Core\Routing\Route;

/**
 * Stores registered application routes, route groups, and global middlewares.
 *
 * Single Responsibility: Holds state of declared routes and enables flat introspection.
 *
 * @internal Implementation detail of Router. Do not depend on this class directly.
 * @package Zara\Core\Routing\Internal
 */
class RouteRegistry implements RouteRegistryInterface
{
    /** @var array<string, list<array{route: Route, callable: string|array|callable}>> */
    private array $routes = [];

    /** @var list<array{prefix: string, namespace: string, middlewares: list<string>}> */
    private array $groupStack = [];

    private string $defaultNamespace = '';

    /** @var list<string> */
    private array $globalMiddlewares = [];

    /** @var array<string, Route>|null */
    private ?array $nameIndex = null;

    public function get(string $path, callable|array|string $callable): RouteInterface
    {
        return $this->addRoute('GET', $path, $callable);
    }

    public function post(string $path, callable|array|string $callable): RouteInterface
    {
        return $this->addRoute('POST', $path, $callable);
    }

    public function put(string $path, callable|array|string $callable): RouteInterface
    {
        return $this->addRoute('PUT', $path, $callable);
    }

    public function delete(string $path, callable|array|string $callable): RouteInterface
    {
        return $this->addRoute('DELETE', $path, $callable);
    }

    public function patch(string $path, callable|array|string $callable): RouteInterface
    {
        return $this->addRoute('PATCH', $path, $callable);
    }

    public function options(string $path, callable|array|string $callable): RouteInterface
    {
        return $this->addRoute('OPTIONS', $path, $callable);
    }

    public function any(string $path, callable|array|string $callable): void
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $this->addRoute($method, $path, $callable);
        }
    }

    public function group(array $options, Closure $callback, object $context): void
    {
        $parent = end($this->groupStack) ?: [];

        $parentNamespace = $parent['namespace'] ?? '';
        $childNamespace  = $options['namespace'] ?? '';

        $namespace = match (true) {
            $parentNamespace !== '' && $childNamespace !== ''
                => trim($parentNamespace, '\\') . '\\' . trim($childNamespace, '\\'),
            $parentNamespace !== '' => $parentNamespace,
            default                 => $childNamespace,
        };

        $this->groupStack[] = [
            'prefix'      => trim(($parent['prefix'] ?? '') . '/' . ($options['prefix'] ?? ''), '/'),
            'namespace'   => $namespace,
            'middlewares' => array_merge(
                $parent['middlewares'] ?? [],
                $this->resolveGroupMiddlewares($options)
            ),
        ];

        try {
            $callback($context);
        } finally {
            array_pop($this->groupStack);
        }
    }

    public function addGlobalMiddleware(string $middlewareClass): static
    {
        $this->globalMiddlewares[] = $middlewareClass;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getGlobalMiddlewares(): array
    {
        return $this->globalMiddlewares;
    }

    public function setDefaultNamespace(string $namespace): static
    {
        $this->defaultNamespace = $namespace;

        return $this;
    }

    public function getDefaultNamespace(): string
    {
        return $this->defaultNamespace;
    }

    public function hasRoutes(): bool
    {
        return !empty($this->routes);
    }

    public function all(): array
    {
        return $this->routes;
    }

    public function setRoutes(array $routes): static
    {
        $this->routes = $routes;
        $this->nameIndex = null;

        return $this;
    }

    public function findByName(string $name): ?RouteInterface
    {
        $this->nameIndex ??= $this->buildNameIndex();

        return $this->nameIndex[$name] ?? null;
    }

    private function buildNameIndex(): array
    {
        $index = [];

        foreach ($this->routes as $method => $entries) {
            foreach ($entries as ['route' => $route]) {
                $routeName = $route->getName();

                if ($routeName === null) {
                    continue;
                }

                if (isset($index[$routeName]) && $index[$routeName] !== $route) {
                    throw new RuntimeException(
                        sprintf(
                            "Route name '%s' is declared multiple times (last conflict on '%s %s'). Each named route must have a unique name.",
                            $routeName,
                            $method,
                            $route->getPath()
                        )
                    );
                }

                $index[$routeName] = $route;
            }
        }

        return $index;
    }

    public function getRoutes(): array
    {
        $flat = [];

        foreach ($this->routes as $method => $entries) {
            foreach ($entries as ['route' => $route, 'callable' => $callable]) {
                $flat[] = [
                    'method'      => $method,
                    'path'        => '/' . $route->getPath(),
                    'handler'     => $this->stringifyCallable($callable),
                    'middlewares' => $route->getMiddlewares(),
                ];
            }
        }

        return $flat;
    }

    private function stringifyCallable(callable|array|string $callable): string
    {
        if (is_string($callable)) {
            return $callable;
        }

        if (is_array($callable) && count($callable) === 2 && is_string($callable[0]) && is_string($callable[1])) {
            return "{$callable[0]}::{$callable[1]}";
        }

        return $callable instanceof Closure ? '{closure}' : 'callable';
    }

    /**
     * @return list<string>
     */
    private function resolveGroupMiddlewares(array $options): array
    {
        if (!array_key_exists('middlewares', $options)) {
            return [];
        }

        if (!is_array($options['middlewares'])) {
            throw new RuntimeException(
                "Group option 'middlewares' must be a list of strings (class-string)."
            );
        }

        foreach ($options['middlewares'] as $middleware) {
            if (!is_string($middleware)) {
                throw new RuntimeException(
                    "Each group middleware must be a class name as a string."
                );
            }
        }

        return array_values($options['middlewares']);
    }

    private function addRoute(string $method, string $path, callable|array|string $callable): RouteInterface
    {
        $group = end($this->groupStack) ?: [];

        $path        = trim(($group['prefix'] ?? '') . '/' . ltrim($path, '/'), '/');
        $namespace   = $group['namespace']   ?? $this->defaultNamespace;
        $middlewares = $group['middlewares'] ?? [];

        $route = new Route($path);

        if ($namespace !== '') {
            $route->setNamespace($namespace);
        }

        if (!empty($middlewares)) {
            $route->setMiddlewares($middlewares);
        }

        $this->routes[strtoupper($method)][] = [
            'route'    => $route,
            'callable' => $callable,
        ];

        $this->nameIndex = null;

        return $route;
    }
}