<?php
declare(strict_types=1);

namespace Zara\Entity\Relation\Interfaces;

use Zara\Entity\Relation\LazyRelation;

interface RelationMetadataInterface
{
    public function targetClass(): string;

    /**
     * A single batched query for a set of owner rows.
     * OneToMany -> result grouped by owner id.
     * ManyToOne -> result grouped by target id.
     *
     * @param array<int, array<string, mixed>> $ownerRows raw rows
     * @return array<int|string, mixed>
     */
    public function preload(RelationBatchLoaderInterface $loader, array $ownerRows, string $ownerIdColumn): array;

    /**
     * @param array<string, mixed> $ownerRow raw owner row
     * @param int|string|null $ownerId owner id, already resolved by the caller
     * @param array<int|string, mixed>|null $preloaded preload() result, if it was called
     */
    public function buildLazyRelation(
        RelationLoaderInterface $loader,
        array $ownerRow,
        int|string|null $ownerId,
        ?array $preloaded = null,
    ): LazyRelation;
}