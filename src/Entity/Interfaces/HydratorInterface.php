<?php
declare(strict_types=1);

namespace Zara\Entity\Interfaces;

interface HydratorInterface
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $preloadedRelations
     */
    public function hydrate(string $entityClass, array $data, array $preloadedRelations = []): object;

    /**
     * @return array<string, mixed>
     */
    public function extract(object $entity): array;

    /**
     * Attaches newly requested relations to an entity that already exists
     * (e.g. returned from the IdentityMap), without rebuilding it.
     *
     * @param array<string, mixed> $preloadedRelations
     */
    public function attachPreloadedRelations(object $entity, array $preloadedRelations): void;

    /**
     * Forces the named relation property's LazyRelation to re-resolve on
     * its next get() — used after a pivot write makes an already-resolved
     * #[ManyToMany] collection stale. See LazyRelation::invalidate() for
     * why this is a no-op on a relation that was eager-loaded rather than
     * lazily resolved.
     */
    public function invalidateRelation(object $entity, string $propertyName): void;
}