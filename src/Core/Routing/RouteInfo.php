<?php

declare(strict_types=1);

namespace Zara\Core\Routing;

use Zara\Core\Routing\Interfaces\RouteInfoInterface;

/**
 * Value Object holding extracted route information after a successful match.
 */
final class RouteInfo implements RouteInfoInterface
{
    /**
     * @param array<string, string> $params
     * @param list<mixed> $middlewares
     */
    public function __construct(
        private readonly array $params,
        private readonly array $middlewares,
        private readonly string $namespace = '',
        private readonly ?string $name = null,
    ) {}

    public function getParams(): array
    {
        return $this->params;
    }

    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function toArray(): array
    {
        return [
            'params'      => $this->params,
            'middlewares' => $this->middlewares,
            'namespace'   => $this->namespace,
            'name'        => $this->name,
        ];
    }
}