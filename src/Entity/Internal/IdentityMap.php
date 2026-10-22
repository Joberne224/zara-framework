<?php
declare(strict_types=1);

namespace Zara\Entity\Internal;

/**
 * First-level identity map: one in-memory instance per (entity class, id)
 * for the lifetime of the current unit of work.
 */
final class IdentityMap
{
    /** @var array<string, object> */
    private array $entities = [];

    public function has(string $entityClass, int|string $id): bool
    {
        return isset($this->entities[$this->key($entityClass, $id)]);
    }

    public function get(string $entityClass, int|string $id): ?object
    {
        return $this->entities[$this->key($entityClass, $id)] ?? null;
    }

    public function set(string $entityClass, int|string $id, object $entity): void
    {
        $this->entities[$this->key($entityClass, $id)] = $entity;
    }

    public function forget(string $entityClass, int|string $id): void
    {
        unset($this->entities[$this->key($entityClass, $id)]);
    }

    /** @return object[] */
    public function all(): array
    {
        return array_values($this->entities);
    }

    public function clear(): void
    {
        $this->entities = [];
    }

    private function key(string $entityClass, int|string $id): string
    {
        return $entityClass . ':' . $id;
    }
}