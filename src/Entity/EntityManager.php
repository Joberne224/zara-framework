<?php
declare(strict_types=1);

namespace Zara\Entity;

use Zara\Database\Query\QueryBuilder;
use Zara\Entity\Internal\EntityCache;
use Zara\Entity\Internal\EntityPersister;
use Zara\Entity\Internal\IdentityMap;
use Zara\Entity\Internal\UnitOfWork;
use Zara\Entity\Internal\WriteResult;
use Zara\Entity\Metadata\EntityMetadata;
use Zara\Entity\Metadata\MetadataFactory;
use Zara\Entity\Relation\ManyToManyMetadata;
use Zara\Entity\Relation\Interfaces\PivotWriterInterface;
use Zara\Entity\Relation\Interfaces\RelationBatchLoaderInterface;
use Zara\Entity\Interfaces\EntityManagerInterface;
use Zara\Entity\Interfaces\HydratorInterface;
use Zara\Database\DatabaseInterface;

final class EntityManager implements EntityManagerInterface
{
    private readonly DatabaseInterface $db;
    private readonly HydratorInterface $hydrator;
    private readonly MetadataFactory $metadataFactory;
    private readonly TrackedHydrator $trackedHydrator;
    private readonly SnapshotRegistry $snapshots;
    private readonly RelationBatchLoaderInterface $relationBatchLoader;
    private readonly PivotWriterInterface $pivotWriter;

    private readonly IdentityMap $identityMap;
    private readonly EntityCache $entityCache;
    private readonly UnitOfWork $unitOfWork;
    private readonly EntityPersister $persister;
    private readonly ?\Psr\EventDispatcher\EventDispatcherInterface $dispatcher;

    public function __construct(EntityServices $services, int $cacheTtl = 3600)
    {
        $this->db = $services->db;
        $this->hydrator = $services->hydrator;
        $this->metadataFactory = $services->metadataFactory;
        $this->trackedHydrator = $services->trackedHydrator;
        $this->relationBatchLoader = $services->relationBatchLoader;
        $this->pivotWriter = $services->pivotWriter;
        $this->dispatcher = $services->dispatcher;

        // Both shared with TrackedHydrator (and thus RelationLoader): one
        // instance, one owner, read through its accessors rather than
        // injected here as a second, independently-wired copy.
        $this->identityMap = $services->trackedHydrator->identityMap();
        $this->snapshots = $services->trackedHydrator->snapshots();

        $this->entityCache = new EntityCache($services->cache, $cacheTtl);
        $this->unitOfWork = new UnitOfWork();
        $this->persister = new EntityPersister(
            $services->db,
            $services->hydrator,
            $services->metadataFactory,
            $this->snapshots,
            $services->dispatcher,
        );
    }

    /**
     * @param string[] $with Relation names to eager-load (anti N+1)
     */
    public function find(string $entityClass, int|string $id, array $with = []): ?object
    {
        $metadata = $this->metadataFactory->metadataFor($entityClass);
        $id = $this->normalizeId($entityClass, $metadata, $id);

        if ($this->identityMap->has($entityClass, $id)) {
            $existing = $this->identityMap->get($entityClass, $id);

            // Before this fix, find($id, ['relation']) on an already-tracked
            // entity returned immediately without ever looking at $with —
            // a second find() call asking for a relation the first call
            // didn't request would silently get an entity whose relation is
            // still unresolved. Reconstructing a row from the entity itself
            // (extract(), not a DB round trip) is enough: OneToMany only
            // needs the id column, ManyToOne only needs its own foreign key
            // column, both of which the entity already holds in memory.
            if ($with !== []) {
                $ownerRow = $this->hydrator->extract($existing);
                $preloaded = $this->preloadRelations($metadata, [$ownerRow], $with);
                $this->hydrator->attachPreloadedRelations($existing, $preloaded);
            }

            return $existing;
        }

        $row = $this->entityCache->getRow($entityClass, $id);

        if ($row === null) {
            $row = QueryBuilder::table($this->db, $metadata->table)
                ->where($metadata->idColumn, '=', $id)
                ->first();

            if ($row === null) {
                return null;
            }

            $this->entityCache->putRow($entityClass, $id, $row);
        }

        $preloaded = $this->preloadRelations($metadata, [$row], $with);

        return $this->hydrateAndTrack($entityClass, $row, $preloaded);
    }

    /**
     * Real batch resolution, unlike calling find() once per id: any id
     * already in the IdentityMap or L2 cache is resolved without SQL, and
     * every remaining id is fetched in a single WHERE IN — not one
     * `find()` SELECT per id with a WHERE IN tacked on at the end that
     * only ever catches ids absent from the database entirely.
     *
     * @param array<int, int|string> $ids
     * @param string[] $with
     * @return object[] in the same order as $ids, with missing ids simply absent
     */
    public function findMany(string $entityClass, array $ids, array $with = []): array
    {
        if ($ids === []) {
            return [];
        }

        $metadata = $this->metadataFactory->metadataFor($entityClass);
        $ids = array_values(array_unique(array_map(
            fn (int|string $id): int|string => $this->normalizeId($entityClass, $metadata, $id),
            $ids,
        )));

        /** @var array<int|string, object> $entities */
        $entities = [];
        /** @var array<int|string, array<string, mixed>> $rowsToHydrate */
        $rowsToHydrate = [];
        $missingIds = [];

        foreach ($ids as $id) {
            if ($this->identityMap->has($entityClass, $id)) {
                $entities[$id] = $this->identityMap->get($entityClass, $id);
                continue;
            }

            $cachedRow = $this->entityCache->getRow($entityClass, $id);

            if ($cachedRow !== null) {
                $rowsToHydrate[$id] = $cachedRow;
                continue;
            }

            $missingIds[] = $id;
        }

        foreach (array_chunk($missingIds, 1000) as $idBatch) {
            $fetchedRows = QueryBuilder::table($this->db, $metadata->table)
                ->whereIn($metadata->idColumn, $idBatch)
                ->get();

            foreach ($fetchedRows as $row) {
                $rowId = $row[$metadata->idColumn];
                $rowsToHydrate[$rowId] = $row;
                $this->entityCache->putRow($entityClass, $rowId, $row);
            }
        }

        $preloaded = [];

        if ($with !== []) {
            // Owner rows must cover the WHOLE batch: rows fetched from cache/SQL
            // and entities already tracked in the IdentityMap (for which a row
            // is rebuilt from the entity itself, as in find()).
            $ownerRows = array_merge(
                array_values($rowsToHydrate),
                array_map(fn (object $e): array => $this->hydrator->extract($e), array_values($entities)),
            );
            $preloaded = $this->preloadRelations($metadata, $ownerRows, $with);

            // At this point $entities only holds tracked instances.
            foreach ($entities as $entity) {
                $this->hydrator->attachPreloadedRelations($entity, $preloaded);
            }
        }

        foreach ($rowsToHydrate as $id => $row) {
            $entities[$id] = $this->hydrateAndTrack($entityClass, $row, $preloaded);
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($entities[$id])) {
                $ordered[] = $entities[$id];
            }
        }

        return $ordered;
    }

    /**
     * @param string[] $with
     * @return object[]
     */
    public function findAll(string $entityClass, array $with = []): array
    {
        $metadata = $this->metadataFactory->metadataFor($entityClass);
        $rows = QueryBuilder::table($this->db, $metadata->table)->get();
        $preloaded = $this->preloadRelations($metadata, $rows, $with);

        return array_map(
            fn (array $row): object => $this->hydrateAndTrack($entityClass, $row, $preloaded),
            $rows,
        );
    }

    public function hydrateAndTrack(string $entityClass, array $row, array $preloadedRelations = []): object
    {
        // Identity handling lives in TrackedHydrator so that relations loaded
        // by RelationLoader go through exactly the same path.
        return $this->trackedHydrator->hydrateAndTrack($entityClass, $row, $preloadedRelations);
    }

    public function preloadRelations(EntityMetadata $metadata, array $rows, array $with): array
    {
        $preloaded = [];
        foreach ($with as $relationName) {
            $relation = $metadata->relations[$relationName]
                ?? throw new \InvalidArgumentException("{$metadata->table} has no relation '{$relationName}'.");
            $preloaded[$relationName] = $relation->preload($this->relationBatchLoader, $rows, $metadata->idColumn);
        }

        return $preloaded;
    }

    public function persist(object $entity): void
    {
        $this->unitOfWork->scheduleInsert($entity);
    }

    public function remove(object $entity): void
    {
        $this->unitOfWork->scheduleDelete($entity);
    }

    /**
     * @return \WeakMap<object, object> Maps each entity scheduled with persist() to the
     *         instance actually stored (immutable entities never receive their
     *         generated ID, the persisted instance is a new object).
     */
    public function flush(): \WeakMap
    {
        /** @var list<WriteResult> $writes */
        $writes = [];
        /** @var list<array{object, WriteResult}> $inserts */
        $inserts = [];
        /** @var list<array{object, class-string, int|string}> $deletions */
        $deletions = [];

        // Inside the transaction ONLY database writes happen. Snapshots, events,
        // IdentityMap and cache are touched after commit, so a rollback leaves
        // all in-memory state exactly as it was before flush().
        $this->db->transaction(function () use (&$writes, &$inserts, &$deletions): void {
            foreach ($this->unitOfWork->pendingInserts() as $entity) {
                $result = $this->persister->persist($entity, $this->baselineFor($entity), true);
                $writes[] = $result;
                $inserts[] = [$entity, $result];
            }

            foreach ($this->unitOfWork->pendingDeletes() as $entity) {
                $deletions[] = [$entity, $entity::class, $this->persister->delete($entity)];
            }

            foreach ($this->identityMap->all() as $entity) {
                if ($this->unitOfWork->isScheduledForDeletion($entity)) {
                    continue;
                }

                // An immutable entity cannot change in place: its row can only
                // be rewritten through a clone passed to save(). Diffing it on
                // every flush would be pure overhead.
                if ($this->metadataFactory->metadataFor($entity::class)->immutable) {
                    continue;
                }

                $writes[] = $this->persister->persist($entity);
            }
        });

        // The data is committed: never replay these inserts/deletes, even if a
        // listener throws below.
        $this->unitOfWork->clear();

        $savedEntities = [];
        $postCommitFailure = null;
        foreach ($writes as $write) {
            try {
                $this->persister->finalize($write);
            } catch (\Throwable $exception) {
                $postCommitFailure ??= $exception;
            }

            // Unchanged entities are neither reconciled (no useless L2 cache
            // invalidation) nor reported in the flush event.
            if ($write->changed) {
                $this->reconcileSaved($write->entity);
                $savedEntities[] = $write->entity;
            }
        }

        $deletedKeys = [];
        foreach ($deletions as [$entity, $entityClass, $id]) {
            try {
                $this->persister->finalizeDelete($entity, $id);
            } catch (\Throwable $exception) {
                $postCommitFailure ??= $exception;
            }
            $this->reconcileDeleted($entityClass, $id);
            $deletedKeys[] = [$entityClass, $id];
        }

        if ($savedEntities !== [] || $deletedKeys !== []) {
            try {
                $this->dispatcher?->dispatch(new Event\UnitOfWorkFlushed($savedEntities, $deletedKeys));
            } catch (\Throwable $exception) {
                $postCommitFailure ??= $exception;
            }
        }

        /** @var \WeakMap<object, object> $persisted */
        $persisted = new \WeakMap();
        foreach ($inserts as [$scheduled, $result]) {
            $persisted[$scheduled] = $result->entity;
        }

        if ($postCommitFailure !== null) {
            throw $postCommitFailure;
        }

        return $persisted;
    }

    public function save(object $entity): object
    {
        $result = $this->persister->persist($entity, $this->baselineFor($entity));
        $postCommitFailure = null;

        try {
            $this->persister->finalize($result);
        } catch (\Throwable $exception) {
            $postCommitFailure = $exception;
        }

        if ($result->changed) {
            $this->reconcileSaved($result->entity);
        }

        if ($postCommitFailure !== null) {
            throw $postCommitFailure;
        }

        return $result->entity;
    }

    public function delete(object $entity): void
    {
        $id = $this->persister->delete($entity);
        $postCommitFailure = null;

        try {
            $this->persister->finalizeDelete($entity, $id);
        } catch (\Throwable $exception) {
            $postCommitFailure = $exception;
        }

        $this->reconcileDeleted($entity::class, $id);

        if ($postCommitFailure !== null) {
            throw $postCommitFailure;
        }
    }

    public function clearIdentityMap(): void
    {
        $this->identityMap->clear();
        $this->unitOfWork->clear();
    }

    public function attachManyToMany(object $entity, string $relationName, int|string $targetId): void
    {
        $relation = $this->manyToManyRelation($entity, $relationName);
        $ownerId = $this->ownerIdOf($entity);

        $this->pivotWriter->attach(
            $relation->pivotTable,
            $relation->pivotOwnerColumn,
            $relation->pivotTargetColumn,
            $ownerId,
            $targetId,
        );

        $this->hydrator->invalidateRelation($entity, $relationName);
    }

    public function detachManyToMany(object $entity, string $relationName, int|string $targetId): void
    {
        $relation = $this->manyToManyRelation($entity, $relationName);
        $ownerId = $this->ownerIdOf($entity);

        $this->pivotWriter->detach(
            $relation->pivotTable,
            $relation->pivotOwnerColumn,
            $relation->pivotTargetColumn,
            $ownerId,
            $targetId,
        );

        $this->hydrator->invalidateRelation($entity, $relationName);
    }

    public function syncManyToMany(object $entity, string $relationName, array $targetIds): void
    {
        $relation = $this->manyToManyRelation($entity, $relationName);
        $ownerId = $this->ownerIdOf($entity);

        $this->pivotWriter->sync(
            $relation->pivotTable,
            $relation->pivotOwnerColumn,
            $relation->pivotTargetColumn,
            $ownerId,
            $targetIds,
        );

        $this->hydrator->invalidateRelation($entity, $relationName);
    }

    private function manyToManyRelation(object $entity, string $relationName): ManyToManyMetadata
    {
        $metadata = $this->metadataFactory->metadataFor($entity::class);
        $relation = $metadata->relations[$relationName]
            ?? throw new \InvalidArgumentException("{$metadata->table} has no relation '{$relationName}'.");

        if (!$relation instanceof ManyToManyMetadata) {
            throw new \InvalidArgumentException(sprintf(
                '"%s" on %s is not a #[ManyToMany] relation.',
                $relationName,
                $entity::class,
            ));
        }

        return $relation;
    }

    private function ownerIdOf(object $entity): int|string
    {
        $metadata = $this->metadataFactory->metadataFor($entity::class);

        return $this->hydrator->extract($entity)[$metadata->idColumn]
            ?? throw new \LogicException(sprintf(
                'Cannot modify a relation on a %s instance without an id — save() it first.',
                $entity::class,
            ));
    }

    /**
     * Diff base for an entity that has no snapshot of its own — typically a
     * clone of an immutable entity. The snapshot of the tracked instance with
     * the same ID is used, so only the columns the caller really changed are
     * written (and a concurrent change to another column is not overwritten).
     * Returns null when no such instance exists: the whole row is written.
     *
     * @return array<string, mixed>|null
     */
    private function baselineFor(object $entity): ?array
    {
        if ($this->snapshots->originalFor($entity) !== null) {
            return null;
        }

        $metadata = $this->metadataFactory->metadataFor($entity::class);
        $id = $this->hydrator->extract($entity)[$metadata->idColumn] ?? null;

        if ($id === null) {
            return null;
        }

        $tracked = $this->identityMap->get($entity::class, $id);

        return $tracked === null || $tracked === $entity
            ? null
            : $this->snapshots->originalFor($tracked);
    }

    private function reconcileSaved(object $savedEntity): void
    {
        $metadata = $this->metadataFactory->metadataFor($savedEntity::class);
        $savedData = $this->hydrator->extract($savedEntity);
        $savedId = $savedData[$metadata->idColumn] ?? null;

        if ($savedId !== null) {
            $this->identityMap->set($savedEntity::class, $savedId, $savedEntity);
            $this->entityCache->forget($savedEntity::class, $savedId);
        }
    }

    private function reconcileDeleted(string $entityClass, int|string $id): void
    {
        $this->identityMap->forget($entityClass, $id);
        $this->entityCache->forget($entityClass, $id);
    }

    /**
     * Rejects an id that doesn't match the entity's declared id type before
     * it ever reaches SQL. Without this, a string id like "1abc" sent as a
     * bound parameter against an INT column gets silently coerced by MySQL
     * (e.g. WHERE id = '1abc' matches id = 1), returning the wrong entity
     * instead of failing — this is not a SQL injection (the value is always
     * bound, never interpolated), just a silent type-confusion bug.
     */
    private function normalizeId(string $entityClass, EntityMetadata $metadata, int|string $id): int|string
    {
        $type = $metadata->idProperty()->getType();

        if (!$type instanceof \ReflectionNamedType || $type->getName() !== 'int' || is_int($id)) {
            return $id;
        }

        $filtered = filter_var($id, FILTER_VALIDATE_INT);

        if ($filtered === false) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid ID "%s" for entity "%s": expected an integer.',
                $id,
                $entityClass,
            ));
        }

        return $filtered;
    }
}
