<?php

declare(strict_types=1);

namespace Zara\Core\Routing;

use Closure;
use Zara\Core\Routing\Interfaces\RouterInterface;
use Zara\Core\Routing\Internal\RouteRegistry;
use Zara\Core\Routing\Internal\RouteMatcher;
use Zara\Core\Routing\Loader\DelegatingRouteLoader;
use Zara\Core\Http\Interfaces\RequestInterface;
use Zara\Core\Routing\Interfaces\RouteMatchInterface;
use Zara\Core\Routing\Loader\RouteLoaderInterface;
use Zara\Core\Routing\Interfaces\RouteCacheInterface;
use Zara\Core\Routing\Interfaces\RouteMatcherInterface;
use Zara\Core\Routing\Interfaces\RouteRegistryInterface;
use InvalidArgumentException;
use RuntimeException;
use Zara\Core\Routing\Interfaces\RouteInterface;

/**
 * Main Facade for registering routes and matching incoming HTTP requests.
 *
 * @package Zara\Core\Routing
 */
class Router implements RouterInterface
{
    private ?RouteLoaderInterface $routeLoader;

    public function __construct(
        private readonly ?RouteCacheInterface   $cache = null,
        private readonly RouteRegistryInterface $registry = new RouteRegistry(),
        private readonly RouteMatcherInterface  $matcher = new RouteMatcher(),
        ?RouteLoaderInterface $routeLoader = null
    ) {
        $this->routeLoader = $routeLoader;

        if ($this->cache?->exists()) {
            $this->registry->setRoutes($this->cache->read());
        }
    }

    // -------------------------------------------------------------------------
    // Route Loading & Cache Management
    // -------------------------------------------------------------------------

    public function load(string $routesFile): void
    {
        if ($this->registry->hasRoutes()) {
            return;
        }

        $loader = $this->routeLoader ?? new DelegatingRouteLoader($this);
        $loader->load($routesFile);
    }

    public function compile(): void
    {
        if ($this->cache === null) {
            throw new RuntimeException(
                'No RouteCache injected. Pass a RouteCache instance to the constructor to enable route caching.'
            );
        }

        $this->cache->write($this->registry->all());
    }

    public function clear(): void
    {
        if ($this->cache === null) {
            throw new RuntimeException('No RouteCache injected.');
        }

        $this->cache->clear();
    }

    public function hasCache(): bool
    {
        return $this->cache !== null;
    }

    public function isCached(): bool
    {
        return $this->cache?->exists() ?? false;
    }

    public function getCachePath(): ?string
    {
        return $this->cache?->getPath();
    }

    // -------------------------------------------------------------------------
    // Route Registration (Delegated to RouteRegistry)
    // -------------------------------------------------------------------------

    public function get(string $path, callable|array|string $callable): RouteInterface
    {
        return $this->registry->get($path, $callable);
    }

    public function post(string $path, callable|array|string $callable): RouteInterface
    {
        return $this->registry->post($path, $callable);
    }

    public function put(string $path, callable|array|string $callable): RouteInterface
    {
        return $this->registry->put($path, $callable);
    }

    public function delete(string $path, callable|array|string $callable): RouteInterface
    {
        return $this->registry->delete($path, $callable);
    }

    public function patch(string $path, callable|array|string $callable): RouteInterface
    {
        return $this->registry->patch($path, $callable);
    }

    public function options(string $path, callable|array|string $callable): RouteInterface
    {
        return $this->registry->options($path, $callable);
    }

    public function any(string $path, callable|array|string $callable): void
    {
        $this->registry->any($path, $callable);
    }

    public function group(array $options, Closure $callback): void
    {
        $this->registry->group($options, fn (object $router) => $callback($router), $this);
    }

    public function addGlobalMiddleware(string $middlewareClass): static
    {
        $this->registry->addGlobalMiddleware($middlewareClass);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getGlobalMiddlewares(): array
    {
        return $this->registry->getGlobalMiddlewares();
    }

    public function setDefaultNamespace(string $namespace): static
    {
        $this->registry->setDefaultNamespace($namespace);

        return $this;
    }

    /**
     * @return list<array{method: string, path: string, handler: string, middlewares: list<string>}>
     */
    public function getRoutes(): array
    {
        return $this->registry->getRoutes();
    }

    // -------------------------------------------------------------------------
    // URL Generation
    // -------------------------------------------------------------------------

    /**
     * Generates a URL from a named route and parameters.
     *
     * @param array<string, scalar> $params
     *
     * @throws RuntimeException If no route is registered with the given name.
     * @throws InvalidArgumentException If a required route parameter is missing.
     */
    public function route(string $name, array $params = []): string
    {
        $route = $this->registry->findByName($name);

        if ($route === null) {
            throw new RuntimeException(sprintf("No route found with name '%s'.", $name));
        }

        $url = '/' . ltrim($route->generate($params), '/');

        // Parameters not present in path placeholders become query string parameters
        $extra = array_diff_key($params, array_flip($route->getParameterNames()));

        if ($extra !== []) {
            $url .= '?' . http_build_query($extra);
        }

        return $url;
    }

    // -------------------------------------------------------------------------
    // Route Matching (Delegated to RouteMatcher)
    // -------------------------------------------------------------------------

    public function match(RequestInterface $request): RouteMatchInterface
    {
        return $this->matchPath(
            $request->getMethod()->getName(),
            $request->getUri()->getPath()
        );
    }

    /**
     * Resolves a route from raw HTTP method and URI without requiring a full Request instance.
     * Useful for CLI commands (e.g., route:match) and unit testing.
     */
    public function matchPath(string $method, string $uri): RouteMatchInterface
    {
        if (!$this->registry->hasRoutes()) {
            throw new RuntimeException('Routes are not loaded. Call Router::load() first.');
        }

        return $this->matcher->matchPath(
            $this->registry->all(),
            $method,
            $uri,
            $this->registry->getDefaultNamespace()
        );
    }
}