<?php
declare(strict_types=1);

namespace Zara\Entity;

use Zara\Entity\Internal\IdentityMap;
use Zara\Entity\Metadata\MetadataFactory;
use Zara\Entity\Interfaces\HydratorInterface;

/**
 * Extracted from EntityManager::hydrateAndTrack() so it can be shared with
 * RelationLoader without the latter depending on EntityManager (which would in
 * turn depend on the relation loader — a cycle avoided by isolating here the
 * only logic that is truly shared: hydrate + track).
 *
 * Tracking means two things, so that EVERY loading path (find, findMany,
 * custom queries, relations) behaves the same:
 *  - one instance per (class, id): a row already in the IdentityMap is never
 *    hydrated a second time;
 *  - a snapshot of the hydrated entity, built with HydratorInterface::extract(),
 *    i.e. in the exact same representation EntityPersister::diff() compares
 *    against. Snapshotting the raw database row instead would report false
 *    changes for any column whose custom caster does not round-trip to the
 *    identical raw value (date formats, JSON whitespace, ...).
 *
 * IdentityMap AND SnapshotRegistry are both internal: created here, never
 * injected, so application code and the composition root never have to know
 * either exists. EntityManager (same package) reads them through identityMap()/
 * snapshots(), which guarantees every consumer — EntityManager itself,
 * EntityPersister, RelationLoader via this class — shares the exact same
 * two instances by construction, rather than by DI wiring staying consistent
 * by convention. SnapshotRegistry used to be constructor-injected instead,
 * like IdentityMap once was: nothing then prevented two independently-wired
 * instances from silently diverging — the exact class of bug this
 * construction-time guarantee exists to close off.
 */
final class TrackedHydrator
{
    private readonly IdentityMap $identityMap;
    private readonly SnapshotRegistry $snapshots;

    public function __construct(
        private readonly HydratorInterface $hydrator,
        private readonly MetadataFactory $metadataFactory,
    ) {
        $this->identityMap = new IdentityMap();
        $this->snapshots = new SnapshotRegistry();
    }

    /**
     * @internal For EntityManager only. Not part of the public API.
     */
    public function identityMap(): IdentityMap
    {
        return $this->identityMap;
    }

    /**
     * @internal For EntityManager (which hands it to EntityPersister) only.
     * Not part of the public API.
     */
    public function snapshots(): SnapshotRegistry
    {
        return $this->snapshots;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<int|string, mixed>> $preloadedRelations
     */
    public function hydrateAndTrack(string $entityClass, array $row, array $preloadedRelations = []): object
    {
        $metadata = $this->metadataFactory->metadataFor($entityClass);
        $id = $row[$metadata->idColumn] ?? null;

        if ($id !== null && $this->identityMap->has($entityClass, $id)) {
            $existing = $this->identityMap->get($entityClass, $id);
            $this->hydrator->attachPreloadedRelations($existing, $preloadedRelations);

            return $existing;
        }

        $entity = $this->hydrator->hydrate($entityClass, $row, $preloadedRelations);

        if ($id !== null) {
            $this->snapshots->remember($entity, $this->hydrator->extract($entity));
            $this->identityMap->set($entityClass, $id, $entity);
        }

        return $entity;
    }
}