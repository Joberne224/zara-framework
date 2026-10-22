<?php
declare(strict_types=1);

namespace Zara\Entity\Internal;

/**
 * Outcome of a write performed by EntityPersister, with NO side effect applied
 * yet (no snapshot update, no event). Those are applied through
 * EntityPersister::finalize() once the surrounding transaction has committed,
 * so a rollback never leaves snapshots claiming the database holds values
 * that were never committed.
 * 
 * @internal Not part of the public API; may change in any release.
 */
final class WriteResult
{
    /**
     * @param object $entity The instance that must be tracked after the write
     *                       (a new instance for inserts and versioned updates)
     * @param bool $changed Whether the database was actually written
     * @param array<string, mixed>|null $snapshot Data to remember for $entity, null to leave it untouched
     * @param object|null $event Event to dispatch after commit
     */
    public function __construct(
        public readonly object $entity,
        public readonly bool $changed,
        public readonly ?array $snapshot = null,
        public readonly ?object $event = null,
    ) {
    }
}