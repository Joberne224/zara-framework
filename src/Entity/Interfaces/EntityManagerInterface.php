<?php
declare(strict_types=1);

namespace Zara\Entity\Interfaces;

use Zara\Entity\Metadata\EntityMetadata;

interface EntityManagerInterface
{
    public function find(string $entityClass, int|string $id, array $with = []): ?object;

    /**
     * @param array<int, int|string> $ids
     * @return object[]
     */
    public function findMany(string $entityClass, array $ids, array $with = []): array;

    /** @return object[] */
    public function findAll(string $entityClass, array $with = []): array;

    public function hydrateAndTrack(string $entityClass, array $row, array $preloadedRelations = []): object;

    /** @return array<string, array<int|string, mixed>> */
    public function preloadRelations(EntityMetadata $metadata, array $rows, array $with): array;

    public function save(object $entity): object;

    public function delete(object $entity): void;

    /**
     * Unit-of-work style batch API: persist()/remove() schedule, flush()
     * commits everything in one transaction. Implemented on EntityManager
     * itself rather than solely on a concrete subclass, so code depending
     * only on this interface (e.g. AbstractRepository) always reaches them.
     */
    public function persist(object $entity): void;

    public function remove(object $entity): void;

    /**
     * @return \WeakMap<object, object> Maps each entity scheduled with persist() to the
     *         instance actually stored. Immutable entities never receive their
     *         generated ID: read it from the returned instance.
     */
    public function flush(): \WeakMap;

    public function clearIdentityMap(): void;

    /**
     * Adds a #[ManyToMany] association, if it doesn't already exist.
     * Invalidates the entity's cached relation so the next get() on it
     * re-resolves — a no-op if that relation was eager-loaded rather than
     * lazily resolved; see LazyRelation::invalidate().
     *
     * @throws \InvalidArgumentException if $relationName doesn't name a
     *         #[ManyToMany] relation on $entity
     */
    public function attachManyToMany(object $entity, string $relationName, int|string $targetId): void;

    /**
     * Removes a #[ManyToMany] association, if present. Same invalidation
     * and error behavior as attachManyToMany().
     */
    public function detachManyToMany(object $entity, string $relationName, int|string $targetId): void;

    /**
     * Replaces the entity's entire set of associations for this relation
     * with exactly $targetIds, in one transaction. Same invalidation and
     * error behavior as attachManyToMany().
     *
     * @param array<int, int|string> $targetIds
     */
    public function syncManyToMany(object $entity, string $relationName, array $targetIds): void;
}