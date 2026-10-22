<?php
declare(strict_types=1);

namespace Zara\Entity\Internal;

use Psr\SimpleCache\CacheInterface;

/**
 * Thin wrapper around the optional PSR-16 (L2) cache storing raw rows.
 * Owns key formatting so EntityManager stays agnostic of it.
 */
final class EntityCache
{
    public function __construct(
        private readonly ?CacheInterface $cache,
        private readonly int $ttl,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function getRow(string $entityClass, int|string $id): ?array
    {
        if ($this->cache === null) {
            return null;
        }

        /** @var array<string, mixed>|null $row */
        $row = $this->cache->get($this->key($entityClass, $id));
       
        return $row;
    }

    /** @param array<string, mixed> $row */
    public function putRow(string $entityClass, int|string $id, array $row): void
    {
        $this->cache?->set($this->key($entityClass, $id), $row, $this->ttl);
    }

    public function forget(string $entityClass, int|string $id): void
    {
        $this->cache?->delete($this->key($entityClass, $id));
    }

    /**
     * PSR-16 reserves the characters {}()/\@: and only guarantees support for
     * A-Z a-z 0-9 _ . — a key containing anything else may throw
     * InvalidArgumentException on strict implementations. Class names are
     * mapped with dots, and IDs outside the safe set are hashed.
     */
    private function key(string $entityClass, int|string $id): string
    {
        $id = (string) $id;
        $safeId = preg_match('/^[A-Za-z0-9_.]+$/', $id) === 1 ? $id : 'h' . sha1($id);

        return 'mapper.' . str_replace('\\', '.', $entityClass) . '.' . $safeId;
    }
}