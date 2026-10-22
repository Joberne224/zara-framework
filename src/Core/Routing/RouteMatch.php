<?php
declare(strict_types=1);

namespace Zara\Core\Routing;

use Zara\Core\Routing\Interfaces\RouteMatchInterface;

/**
 * Représente le résultat d'une correspondance entre une requête et une route.
 */
final class RouteMatch implements RouteMatchInterface 
{
    /** @var callable|array|string */
    private mixed $callable;

    /**
     * @param array<string, string> $params
     * @param list<mixed> $middlewares
     * @param callable|array|string $callable
     */
    public function __construct(
        private readonly array $params,
        private readonly array $middlewares,
        mixed $callable,
        private readonly string $namespace = '',
        private readonly ?string $class = null,
        private readonly ?string $method = null,
        private readonly ?string $name = null, 
    ) {
        $this->callable = $callable;
    }

    /** @return array<string, string> */
    public function getParams(): array
    {
        return $this->params;
    }

    /** @return list<mixed> */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    /** @return callable|array|string */
    public function getCallable(): mixed
    {
        return $this->callable;
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    public function getClass(): ?string
    {
        return $this->class;
    }

    public function getMethod(): ?string
    {
        return $this->method;
    }
    
    public function getName(): ?string
    {
        return $this->name;
    }
    public function hasController(): bool
    {
        return $this->class !== null && $this->method !== null;
    }

    /** @return array{params: array<string,string>, middlewares: list<mixed>, namespace: string, callable: callable|array|string, class?: string, method?: string} */
    public function toArray(): array
    {
        $data = [
            'params'      => $this->params,
            'middlewares' => $this->middlewares,
            'namespace'   => $this->namespace,
            'callable'    => $this->callable,
        ];

        if ($this->class !== null) {
            $data['class'] = $this->class;
        }

        if ($this->method !== null) {
            $data['method'] = $this->method;
        }

        return $data;
    }
}