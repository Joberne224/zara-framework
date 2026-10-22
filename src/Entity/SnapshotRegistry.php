<?php
declare(strict_types=1);

namespace Zara\Entity;

/**
 * Keyed by instance (WeakMap), not by "class#id": two PHP objects
 * representing the same row can no longer share — and therefore overwrite —
 * the same snapshot. An entry disappears automatically once the entity has
 * no remaining reference, with no memory leak in worker mode.
 */
final class SnapshotRegistry
{
    /** @var \WeakMap<object, array<string, mixed>> */
    private \WeakMap $snapshots;

    public function __construct()
    {
        $this->snapshots = new \WeakMap();
    }

    /** @param array<string, mixed> $data */
    public function remember(object $entity, array $data): void
    {
        $this->snapshots[$entity] = $data;
    }

    /** @return array<string, mixed>|null */
    public function originalFor(object $entity): ?array
    {
        return $this->snapshots[$entity] ?? null;
    }

    public function forget(object $entity): void
    {
        unset($this->snapshots[$entity]);
    }
}