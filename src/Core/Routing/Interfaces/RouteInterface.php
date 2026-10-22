<?php

declare(strict_types=1);

namespace Zara\Core\Routing\Interfaces;

use InvalidArgumentException;

/**
 * Contract representing an application route definition.
 *
 * Route is a descriptive Value Object storing the path, assigned callables,
 * class-string middlewares, and group namespaces. Compilation and regex matching
 * logic are delegated to {@see RouteCompilerInterface}.
 *
 * Route instances are thread-safe and stateless beyond their initial build phase:
 * tryMatch() returns extracted parameters without mutating instance state, making
 * it safe for high concurrency environments (e.g. persistent workers like Swoole/FrankenPHP).
 */
interface RouteInterface
{
    /**
     * Attempts to match a given URL path against this route.
     *
     * @return array<string, string>|null Extracted parameters array or null if the URL does not match.
     */
    public function tryMatch(string $url): ?array;

    /**
     * Builds a RouteInfo transfer object from pre-extracted parameters.
     *
     * @param array<string, string> $params
     */
    public function toRouteInfo(array $params): RouteInfoInterface;

    /**
     * Adds a custom regular expression constraint to a path parameter.
     *
     * @throws InvalidArgumentException If the parameter name or pattern is invalid.
     */
    public function with(string $param, string $pattern): static;

    /**
     * Expressive alias to apply multiple regex parameter constraints.
     *
     * @param array<string, string> $constraints
     *
     * @throws InvalidArgumentException
     */
    public function where(array $constraints): static;

    /**
     * Assigns a unique name to the route for URL generation via Router::route($name).
     */
    public function name(string $name): static;

    /**
     * Returns the assigned route name or null if unassigned.
     */
    public function getName(): ?string;

    /**
     * Reconstructs a concrete URL path using provided parameters.
     *
     * @param array<string, scalar> $params
     *
     * @throws InvalidArgumentException If a required path parameter is missing from $params.
     */
    public function generate(array $params = []): string;

    /**
     * Returns the names of all parameters declared in the path (required and optional).
     * Used by Router::route() to distinguish parameters feeding the URL path from query string parameters.
     *
     * @return list<string>
     */
    public function getParameterNames(): array;

    /**
     * Returns the original raw route path.
     */
    public function getPath(): string;

    /**
     * Returns the group namespace associated with this route.
     */
    public function getNamespace(): string;

    /**
     * Sets the group namespace associated with this route.
     */
    public function setNamespace(string $namespace): static;

    /**
     * Overwrites the complete list of assigned middleware class strings.
     *
     * @param list<string> $middlewares
     */
    public function setMiddlewares(array $middlewares): static;

    /**
     * Appends a middleware class string without overwriting existing ones.
     */
    public function addMiddleware(string $middlewareClass): static;

    /**
     * Returns the list of assigned middleware class strings.
     *
     * @return list<string>
     */
    public function getMiddlewares(): array;

    /**
     * Returns all registered parameter regex constraints.
     *
     * @return array<string, string>
     */
    public function getConstraints(): array;
}