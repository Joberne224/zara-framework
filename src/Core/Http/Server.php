<?php

declare(strict_types=1);

namespace Zara\Core\Http;

class Server
{
    private array $params;

    public function __construct(array $params)
    {
        $this->params = $params;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->params;
    }
    public function method(): string
    {
        return $this->params['REQUEST_METHOD'] ?? 'GET';
    }
    public function uri(): string
    {
        return $this->params['REQUEST_URI'] ?? '/';
    } 
    public function ip(): string
    {
        return $this->params['REMOTE_ADDR'] ?? '127.0.0.1';
    }
    public function isGet(): bool
    {
        return $this->method() === 'GET';
    }
    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }
    public function isAjax(): bool
    {
        return $this->params['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
    }
}