<?php

declare(strict_types=1);

namespace Zara\Core\Routing;

use Zara\Core\Routing\Interfaces\RouteCompilerInterface;
use Zara\Core\Routing\Interfaces\RouteInfoInterface;
use Zara\Core\Routing\Interfaces\RouteInterface;

/**
 * Value Object representing an application route definition.
 *
 * Delegates compilation and regex evaluation to {@see RouteCompilerInterface}.
 * Safe for high concurrency environments due to immutable matching behavior.
 *
 * @package Zara\Core\Routing
 */
class Route implements RouteInterface
{
    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    /** @var list<string> List of middleware class names (lazy loaded) */
    private array $middlewares = [];

    private string $namespace = '';

    private ?string $name = null;

    private RouteCompilerInterface $compiler;

    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    /**
     * @param string $path Route path (e.g. '/user/{id:int}')
     * @param RouteCompilerInterface|null $compiler Injectable compiler instance for testing or customization.
     */
    public function __construct(string $path, ?RouteCompilerInterface $compiler = null)
    {
        $this->compiler = $compiler ?? new RouteCompiler(trim($path, '/'));
    }

    // -------------------------------------------------------------------------
    // Matching
    // -------------------------------------------------------------------------

    /**
     * Attempts to match $url against this route definition.
     *
     * @return array<string, string>|null Extracted parameters array, or null if the URL does not match.
     */
    public function tryMatch(string $url): ?array
    {
        return $this->compiler->match($url);
    }

    /**
     * Constructs a RouteInfo value object using pre-extracted parameters.
     *
     * @param array<string, string> $params
     */
    public function toRouteInfo(array $params): RouteInfoInterface
    {
        return new RouteInfo($params, $this->middlewares, $this->namespace, $this->name);
    }

    // -------------------------------------------------------------------------
    // Parameter Constraints — Delegated to RouteCompiler
    // -------------------------------------------------------------------------

    /**
     * Adds a custom regular expression constraint to a path parameter.
     */
    public function with(string $param, string $pattern): static
    {
        $this->compiler->constrain($param, $pattern);

        return $this;
    }

    /**
     * Expressive alias to apply multiple regex parameter constraints.
     *
     * @param array<string, string> $constraints
     */
    public function where(array $constraints): static
    {
        $this->compiler->constrainMany($constraints);

        return $this;
    }

    // -------------------------------------------------------------------------
    // Named Routes
    // -------------------------------------------------------------------------

    /**
     * Assigns a unique name to the route for generation via Router::route($name).
     */
    public function name(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Reconstructs a concrete URL path using provided parameters.
     *
     * @param array<string, scalar> $params
     */
    public function generate(array $params = []): string
    {
        return $this->compiler->generate($params);
    }

    /**
     * Returns all parameter names declared in the route path.
     *
     * @return list<string>
     */
    public function getParameterNames(): array
    {
        return $this->compiler->getParameterNames();
    }

    // -------------------------------------------------------------------------
    // Getters / Setters
    // -------------------------------------------------------------------------

    public function getPath(): string
    {
        return $this->compiler->getOriginalPath();
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    public function setNamespace(string $namespace): static
    {
        $this->namespace = $namespace;

        return $this;
    }

    /**
     * Overwrites the complete list of assigned middleware class strings.
     *
     * @param list<string> $middlewares
     */
    public function setMiddlewares(array $middlewares): static
    {
        $this->middlewares = array_values($middlewares);

        return $this;
    }

    /**
     * Appends a middleware class string without overwriting existing ones.
     */
    public function addMiddleware(string $middlewareClass): static
    {
        $this->middlewares[] = $middlewareClass;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    /**
     * Returns registered parameter constraints (delegated to compiler).
     *
     * @return array<string, string>
     */
    public function getConstraints(): array
    {
        return $this->compiler->getConstraints();
    }
}