<?php
declare(strict_types=1);

namespace Zara\Entity\Internal;

use Psr\EventDispatcher\EventDispatcherInterface;
use Zara\Entity\Interfaces\HydratorInterface;
use Zara\Database\DatabaseInterface;
use Zara\Database\Query\QueryBuilder;
use Zara\Entity\Event\EntityCreated;
use Zara\Entity\Event\EntityDeleted;
use Zara\Entity\Event\EntityUpdated;
use Zara\Entity\Metadata\EntityMetadata;
use Zara\Entity\Metadata\MetadataFactory;
use Zara\Entity\SnapshotRegistry;

/**
 * Writes entities to the database (insert/update/delete) with dirty-diffing
 * and optimistic locking. Deliberately unaware of the IdentityMap or L2
 * cache — EntityManager is responsible for reconciling those after a write.
 *
 * persist() and delete() only touch the database. Snapshots and events are
 * applied by finalize() / finalizeDelete(), which the caller must invoke
 * AFTER the surrounding transaction has committed: a rollback must never
 * leave snapshots claiming the database holds uncommitted values, nor
 * listeners notified of changes that never happened.
 */
final class EntityPersister
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly HydratorInterface $hydrator,
        private readonly MetadataFactory $metadataFactory,
        private readonly SnapshotRegistry $snapshots,
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    /**
     * @param array<string, mixed>|null $baseline Snapshot of ANOTHER instance of the same row
     *        (typically the tracked instance an immutable entity was cloned from). It is
     *        only used as the diff base when $entity has no snapshot of its own, so a
     *        modified clone writes just its changed columns instead of the whole row.
     */
    public function persist(object $entity, ?array $baseline = null, bool $forceInsert = false): WriteResult
    {
        $metadata = $this->metadataFactory->metadataFor($entity::class);
        $data = $this->hydrator->extract($entity);
        $id = $data[$metadata->idColumn] ?? null;

        return $forceInsert || $id === null
            ? $this->insert($entity, $metadata, $data)
            : $this->update($entity, $metadata, $data, $id, $baseline);
    }

    /**
     * Deletes the row and returns the deleted ID.
     * Call finalizeDelete() once the surrounding transaction has committed.
     */
    public function delete(object $entity): int|string
    {
        $metadata = $this->metadataFactory->metadataFor($entity::class);
        $data = $this->hydrator->extract($entity);
        $id = $data[$metadata->idColumn]
            ?? throw new \LogicException('Cannot delete an entity without an ID.');

        $affected = QueryBuilder::table($this->db, $metadata->table)
            ->where($metadata->idColumn, '=', $id)
            ->delete();

        if ($affected === 0) {
            throw new \RuntimeException(sprintf(
                'Cannot delete entity "%s" (ID: %s): no matching record was found.',
                $entity::class,
                $id,
            ));
        }

        return $id;
    }

    /**
     * Applies the post-commit effects of a write: snapshot and event.
     */
    public function finalize(WriteResult $result): void
    {
        if ($result->snapshot !== null) {
            $this->snapshots->remember($result->entity, $result->snapshot);
        }

        if ($result->event !== null) {
            $this->dispatcher?->dispatch($result->event);
        }
    }

    /**
     * Applies the post-commit effects of a deletion: snapshot removal and event.
     */
    public function finalizeDelete(object $entity, int|string $id): void
    {
        $this->snapshots->forget($entity);

        $this->dispatcher?->dispatch(new EntityDeleted($entity::class, $id));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insert(object $entity, EntityMetadata $metadata, array $data): WriteResult
    {
        $suppliedId = $data[$metadata->idColumn] ?? null;

        if ($metadata->idGenerated) {
            // Let the database generate it (AUTO_INCREMENT / SERIAL / ROWID) —
            // sending a value here would either be rejected or silently
            // override the generated one, depending on the engine.
            unset($data[$metadata->idColumn]);
        } elseif ($suppliedId === null) {
            throw new \RuntimeException(sprintf(
                'Entity "%s" has a non-generated id (#[Id(generated: false)]) but no id was set before insert.',
                $entity::class,
            ));
        }

        $insertedId = QueryBuilder::table($this->db, $metadata->table)->insert($data);

        // For a generated id, $insertedId (from lastInsertId()) IS the real
        // id. For an application-supplied one, lastInsertId() is meaningless
        // (no auto-increment column exists to report on) — the id the caller
        // already set is the one that was actually written.
        //
        // Caveat on the generated branch: this depends on $insertedId being
        // correct. On MySQL/SQLite that's reliable (AUTO_INCREMENT / ROWID);
        // on PostgreSQL it resolves through SELECT lastval(), the
        // session-wide last generated sequence value — reliable for a
        // BIGSERIAL id, and 0 when the table has no generated column at all
        // (which idGenerated=false already excludes).
        $id = $metadata->idGenerated ? $insertedId : $suppliedId;

        // Re-read the row instead of hydrating from $data: $data only reflects
        // what the entity held *before* the INSERT. Any column with a
        // server-side default (DEFAULT CURRENT_TIMESTAMP, a generated column,
        // a trigger-populated field) was never in $data, so hydrating from it
        // directly would return an entity that looks persisted but diverges
        // from the real row on exactly those columns.
        $row = QueryBuilder::table($this->db, $metadata->table)
            ->where($metadata->idColumn, '=', $id)
            ->first();

        if ($row === null) {
            throw new \RuntimeException(sprintf(
                'Entity "%s" (ID: %s) could not be re-read immediately after insert.',
                $entity::class,
                $id,
            ));
        }

        $created = $this->hydrator->hydrate($entity::class, $row);

        // The snapshot uses extract(), the same representation diff() compares
        // against — not the raw row, which would report false changes (1 vs
        // true, date formats, ...) on the next flush.
        return new WriteResult(
            $created,
            true,
            $this->hydrator->extract($created),
            new EntityCreated($created),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $baseline
     */
    private function update(
        object $entity,
        EntityMetadata $metadata,
        array $data,
        int|string $id,
        ?array $baseline,
    ): WriteResult {
        $ownOriginal = $this->snapshots->originalFor($entity);
        $original = $ownOriginal ?? $baseline;
        $withoutId = array_intersect_key($data, $metadata->columnsWithoutId());
        $updatable = $original === null ? $withoutId : $this->diff($original, $data, $metadata);

        // Short-circuits for ANY entity without a real change, versioned or
        // not. A versioned entity used to skip this guard and received a
        // version bump + an UPDATE on every flush() even without a diff,
        // causing false optimistic-lock failures for other processes while
        // nothing had changed.
        if ($updatable === []) {
            return new WriteResult($entity, false);
        }

        $versionColumn = $metadata->versionColumn;

        if ($versionColumn === null) {
            $affected = QueryBuilder::table($this->db, $metadata->table)
                ->where($metadata->idColumn, '=', $id)
                ->update($updatable);

            if ($affected === 0) {
                throw new \RuntimeException(sprintf(
                    'Cannot update entity "%s" (ID: %s): no matching record was found.',
                    $entity::class,
                    $id,
                ));
            }

            return new WriteResult($entity, true, $data, new EntityUpdated($entity, array_keys($updatable)));
        }

        // The optimistic lock must use the version THIS instance was read with:
        // its own snapshot, else its own data. Never the baseline's, otherwise a
        // stale clone would silently inherit a newer version and bypass the lock.
        $currentVersion = (int) ($ownOriginal[$versionColumn] ?? $data[$versionColumn]
            ?? throw new \LogicException(sprintf(
                'Cannot resolve current version for entity "%s" (ID: %s).',
                $entity::class,
                $id,
            )));

        $updatable[$versionColumn] = $currentVersion + 1;

        $affected = QueryBuilder::table($this->db, $metadata->table)
            ->where($metadata->idColumn, '=', $id)
            ->where($versionColumn, '=', $currentVersion)
            ->update($updatable);

        if ($affected === 0) {
            throw new \RuntimeException(sprintf(
                'Optimistic lock failed for entity "%s" (ID: %s). Record was modified by another process.',
                $entity::class,
                $id,
            ));
        }

        // The version property of $entity still holds the OLD value (it may be
        // readonly) while the database now holds the new one. Keeping $entity
        // with a "new version" snapshot would make the next flush() see a diff
        // on the version column and bump it again. Rehydrating keeps entity and
        // snapshot consistent, exactly like insert() does. Relations already
        // loaded on the old instance are lazy again on the new one.
        $data[$versionColumn] = $updatable[$versionColumn];
        $updated = $this->hydrator->hydrate($entity::class, $data);

        return new WriteResult(
            $updated,
            true,
            $this->hydrator->extract($updated),
            new EntityUpdated($updated, array_keys($updatable)),
        );
    }

    /**
     * @param array<string, mixed> $original
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    private function diff(array $original, array $current, EntityMetadata $metadata): array
    {
        $changed = [];

        foreach ($metadata->columnsWithoutId() as $column => $property) {
            // The version is managed by the persister, never by the caller: a
            // difference here (e.g. a clone against a newer baseline) is not a
            // user change and must not trigger an UPDATE on its own.
            if ($column === $metadata->versionColumn) {
                continue;
            }

            if (!array_key_exists($column, $current)) {
                continue;
            }

            if (!array_key_exists($column, $original) || $original[$column] !== $current[$column]) {
                $changed[$column] = $current[$column];
            }
        }

        return $changed;
    }
}
