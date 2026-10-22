<?php
declare(strict_types=1);

namespace Zara\Entity\Relation;

use Zara\Database\DatabaseInterface;
use Zara\Entity\Relation\Interfaces\RelationLoaderInterface;
use Zara\Entity\Relation\Interfaces\RelationBatchLoaderInterface;
use Zara\Database\Query\QueryBuilder;
use Zara\Entity\Metadata\MetadataFactory;
use Zara\Entity\TrackedHydrator;

final class RelationLoader implements RelationLoaderInterface, RelationBatchLoaderInterface
{
    /**
     * Kept at or below ConditionCompiler::MAX_IN_LIST_SIZE: whereIn() throws
     * above that limit rather than risk the driver's own placeholder limit
     * (65,535 on MySQL/PostgreSQL, 32,766 on recent SQLite). Chunking here,
     * one query per batch, is what actually lets loadManyBatch()/
     * loadOneBatch() preload a relation over an arbitrarily large id list
     * instead of just failing loudly once past the limit.
     */
    private const BATCH_SIZE = 1000;

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly MetadataFactory $metadataFactory,
        private readonly TrackedHydrator $trackedHydrator,
    ) {
    }

    public function loadOne(string $targetClass, int|string $id): ?object
    {
        $metadata = $this->metadataFactory->metadataFor($targetClass);

        $row = QueryBuilder::table($this->db, $metadata->table)
            ->where($metadata->idColumn, '=', $id)
            ->first();

        return $row !== null ? $this->trackedHydrator->hydrateAndTrack($targetClass, $row) : null;
    }

    public function loadMany(string $targetClass, string $foreignKey, int|string $ownerId): array
    {
        $metadata = $this->metadataFactory->metadataFor($targetClass);

        $rows = QueryBuilder::table($this->db, $metadata->table)
            ->where($foreignKey, '=', $ownerId)
            ->get();

        return array_map(
            fn(array $row): object => $this->trackedHydrator->hydrateAndTrack($targetClass, $row),
            $rows,
        );
    }

    public function loadManyBatch(string $targetClass, string $foreignKey, array $ownerIds): array
    {
        $metadata = $this->metadataFactory->metadataFor($targetClass);
        $grouped = [];

        foreach (array_chunk($ownerIds, self::BATCH_SIZE) as $chunk) {
            $rows = QueryBuilder::table($this->db, $metadata->table)
                ->whereIn($foreignKey, $chunk)
                ->get();

            foreach ($rows as $row) {
                $grouped[$row[$foreignKey]][] = $this->trackedHydrator->hydrateAndTrack($targetClass, $row);
            }
        }

        return $grouped;
    }

    public function loadOneBatch(string $targetClass, array $ids): array
    {
        $metadata = $this->metadataFactory->metadataFor($targetClass);
        $byId = [];

        foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
            $rows = QueryBuilder::table($this->db, $metadata->table)
                ->whereIn($metadata->idColumn, $chunk)
                ->get();

            foreach ($rows as $row) {
                $byId[$row[$metadata->idColumn]] = $this->trackedHydrator->hydrateAndTrack($targetClass, $row);
            }
        }

        return $byId;
    }

    /**
     * A single-owner call is just loadManyToManyBatch() for one id: sharing
     * the implementation means a lazy load and a preload of the same
     * relation can never disagree on chunking (both chunk, so neither
     * throws above MAX_IN_LIST_SIZE where the other wouldn't) or on
     * ordering (both now walk the pivot rows in the same order, where
     * before the single-owner path ordered by a scan of the target table
     * instead).
     */
    public function loadManyToMany(
        string $targetClass,
        string $pivotTable,
        string $pivotOwnerColumn,
        string $pivotTargetColumn,
        int|string $ownerId,
    ): array {
        return $this->loadManyToManyBatch(
            $targetClass,
            $pivotTable,
            $pivotOwnerColumn,
            $pivotTargetColumn,
            [$ownerId],
        )[$ownerId] ?? [];
    }

    public function loadManyToManyBatch(
        string $targetClass,
        string $pivotTable,
        string $pivotOwnerColumn,
        string $pivotTargetColumn,
        array $ownerIds,
    ): array {
        // 1. Owner id -> target id pairs from the pivot table. Two queries
        // instead of a JOIN keep hydration on the existing tracked path
        // (loadOneBatch() below) and avoid column-name collisions between
        // pivot and target rows (e.g. both tables having their own "id").
        $pairs = [];
        foreach (array_chunk($ownerIds, self::BATCH_SIZE) as $chunk) {
            $pivotRows = QueryBuilder::table($this->db, $pivotTable)
                ->whereIn($pivotOwnerColumn, $chunk)
                ->get();

            foreach ($pivotRows as $row) {
                $pairs[] = [$row[$pivotOwnerColumn], $row[$pivotTargetColumn]];
            }
        }

        if ($pairs === []) {
            return [];
        }

        // 2. Batch-fetch every distinct target row exactly once — reuses
        // loadOneBatch() itself (chunking, IdentityMap dedup via
        // TrackedHydrator, grouping by target id) rather than
        // re-implementing the same thing here.
        $targetIds = array_values(array_unique(array_column($pairs, 1)));
        $targetsById = $this->loadOneBatch($targetClass, $targetIds);

        // 3. Regroup by owner id using the pivot pairs. Deduplicates
        // (ownerId, targetId) pairs: without a unique constraint on that
        // couple in the pivot table, a duplicate row would otherwise add
        // the same target twice for the same owner.
        $grouped = [];
        $seenPairs = [];

        foreach ($pairs as [$ownerId, $targetId]) {
            $pairKey = $ownerId . ':' . $targetId;

            if (isset($seenPairs[$pairKey]) || !isset($targetsById[$targetId])) {
                continue;
            }

            $seenPairs[$pairKey] = true;
            $grouped[$ownerId][] = $targetsById[$targetId];
        }

        return $grouped;
    }
}