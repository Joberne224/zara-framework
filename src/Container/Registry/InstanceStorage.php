<?php
declare(strict_types=1);

namespace Zara\Container\Registry;

final class InstanceStorage
{
    /** @var array<string, object> */
    private array $instances = [];

    public function set(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }

    public function get(string $id): ?object
    {
        return $this->instances[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]);
    }
}