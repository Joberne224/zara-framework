<?php
declare(strict_types=1);

namespace Zara\Entity\Relation\Interfaces;

/**
 * Writes association rows for a #[ManyToMany] relation's pivot table.
 * Deliberately separate from RelationLoaderInterface/
 * RelationBatchLoaderInterface (read-only) — same SRP split as
 * EntityPersister (writes) vs the read path elsewhere in this package.
 */
interface PivotWriterInterface
{
    /**
     * Adds the (ownerId, targetId) pair if it isn't already there.
     * Idempotent: attaching an already-attached pair is a no-op, not a
     * duplicate-key error or a silently duplicated row.
     */
    public function attach(
        string $pivotTable,
        string $ownerColumn,
        string $targetColumn,
        int|string $ownerId,
        int|string $targetId,
    ): void;

    /**
     * Removes the (ownerId, targetId) pair, if present. A pair that was
     * never attached is a no-op, not an error.
     */
    public function detach(
        string $pivotTable,
        string $ownerColumn,
        string $targetColumn,
        int|string $ownerId,
        int|string $targetId,
    ): void;

    /**
     * Replaces the owner's entire set of associations with exactly
     * $targetIds — removes every pair not in the new set, adds every one
     * that's missing. Runs inside a transaction: a crash partway through
     * must never leave the owner with a partial set.
     *
     * @param array<int, int|string> $targetIds
     */
    public function sync(
        string $pivotTable,
        string $ownerColumn,
        string $targetColumn,
        int|string $ownerId,
        array $targetIds,
    ): void;
}