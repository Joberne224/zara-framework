<?php

declare(strict_types=1);

namespace Zara\Core\Routing\Interfaces;

/**
 * Contract for route information transfer objects.
 */
interface RouteInfoInterface
{
    /**
     * @return array<string, string>
     */
    public function getParams(): array;

    /**
     * @return list<mixed>
     */
    public function getMiddlewares(): array;

    public function getNamespace(): string;

    /**
     * Returns the assigned route name, or null if the matched route was
     * declared without one.
     */
    public function getName(): ?string;

    /**
     * @return array{params: array<string, string>, middlewares: list<mixed>, namespace: string, name: string|null}
     */
    public function toArray(): array;
}