<?php
declare(strict_types=1);

namespace Zara\Entity\Internal;

/**
 * Tracks entities scheduled for insertion or deletion until the next flush.
 * Deliberately unaware of persistence or the identity map.
 */
final class UnitOfWork
{
    /** @var array<int, object> */
    private array $pendingInserts = [];

    /** @var array<int, object> */
    private array $pendingDeletes = [];

    public function scheduleInsert(object $entity): void
    {
        $this->pendingInserts[spl_object_id($entity)] = $entity;
    }

    public function scheduleDelete(object $entity): void
    {
        $this->pendingDeletes[spl_object_id($entity)] = $entity;
    }

    public function isScheduledForDeletion(object $entity): bool
    {
        return isset($this->pendingDeletes[spl_object_id($entity)]);
    }

    /** @return object[] */
    public function pendingInserts(): array
    {
        return array_values($this->pendingInserts);
    }

    /** @return object[] */
    public function pendingDeletes(): array
    {
        return array_values($this->pendingDeletes);
    }

    public function clear(): void
    {
        $this->pendingInserts = [];
        $this->pendingDeletes = [];
    }
}