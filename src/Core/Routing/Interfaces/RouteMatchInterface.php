<?php

declare(strict_types=1);

namespace Zara\Core\Routing\Interfaces;

/**
 * Contract representing the resolved result of a route matching operation.
 */
interface RouteMatchInterface
{
    /**
     * Returns route parameters extracted from the URI.
     *
     * @return array<string, string>
     */
    public function getParams(): array;

    /**
     * Returns the list of middlewares associated with the matched route.
     *
     * @return list<mixed>
     */
    public function getMiddlewares(): array;

    /**
     * Returns the resolved callable or route handler definition.
     *
     * @return callable|array|string
     */
    public function getCallable(): mixed;

    /**
     * Returns the controller namespace if defined.
     */
    public function getNamespace(): string;

    /**
     * Returns the target controller class name if present.
     */
    public function getClass(): ?string;

    /**
     * Returns the target controller method name if present.
     */
    public function getMethod(): ?string;

    /**
     * Returns the assigned route name, or null if the matched route was
     * declared without one.
     */
    public function getName(): ?string;

    /**
     * Checks if the match resolves to a controller class and method pair.
     */
    public function hasController(): bool;

    /**
     * Converts the match instance to an associative array.
     *
     * @return array{params: array<string, string>, middlewares: list<mixed>, namespace: string, callable: callable|array|string, class?: string, method?: string, name?: string}
     */
    public function toArray(): array;
}