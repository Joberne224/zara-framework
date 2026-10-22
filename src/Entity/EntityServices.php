<?php
declare(strict_types=1);

namespace Zara\Entity;

use Psr\SimpleCache\CacheInterface;
use Zara\Database\DatabaseInterface;
use Zara\Entity\Relation\Interfaces\PivotWriterInterface;
use Zara\Entity\Relation\Interfaces\RelationBatchLoaderInterface;
use Zara\Entity\Interfaces\HydratorInterface;
use Zara\Entity\Metadata\MetadataFactory;

/**
 * A pure collaborator bag for EntityManager — every field here is a live
 * service, never a scalar value. Formerly DataMapperConfig: that name and
 * shape didn't match Zara's own convention for a "Config" (DatabaseConfig,
 * HttpClientConfig — scalars and value objects only, no service instances).
 * cacheTtl, the one scalar that used to live here, is now a standalone
 * parameter on EntityManager's own constructor instead.
 *
 * No standalone SnapshotRegistry field: it used to be injected here
 * independently of TrackedHydrator's own copy, with nothing guaranteeing
 * the two were the same instance. TrackedHydrator now builds it internally
 * and exposes it via snapshots() — EntityManager reads it from there (same
 * construction-time sharing guarantee already used for identityMap()).
 */
final class EntityServices
{
    public function __construct(
        public readonly DatabaseInterface $db,
        public readonly HydratorInterface $hydrator,
        public readonly MetadataFactory $metadataFactory,
        public readonly TrackedHydrator $trackedHydrator,
        public readonly RelationBatchLoaderInterface $relationBatchLoader,
        public readonly PivotWriterInterface $pivotWriter,
        public readonly ?CacheInterface $cache = null,
        public readonly ?\Psr\EventDispatcher\EventDispatcherInterface $dispatcher = null,
    ) {
    }
}