<?php
// Zara\Entity\Relation\ManyToManyMetadata.php
declare(strict_types=1);

namespace Zara\Entity\Relation;

use Zara\Entity\Relation\Interfaces\RelationBatchLoaderInterface;
use Zara\Entity\Relation\Interfaces\RelationLoaderInterface;
use Zara\Entity\Relation\Interfaces\RelationMetadataInterface;

final class ManyToManyMetadata implements RelationMetadataInterface
{
    public function __construct(
        private readonly string $targetClass,
        public readonly string $pivotTable,
        public readonly string $pivotOwnerColumn,
        public readonly string $pivotTargetColumn,
    ) {
    }

    public function targetClass(): string
    {
        return $this->targetClass;
    }

    public function preload(RelationBatchLoaderInterface $loader, array $ownerRows, string $ownerIdColumn): array
    {
        $ownerIds = array_values(array_unique(array_map(
            function (array $row) use ($ownerIdColumn): int|string {
                return $row[$ownerIdColumn]
                    ?? throw new \InvalidArgumentException(sprintf(
                        'Cannot preload relation targeting "%s": a row is missing the owner id column "%s". '
                            . 'Ensure any custom query passed to hydrateAll()/preloadRelations() selects it.',
                        $this->targetClass,
                        $ownerIdColumn,
                    ));
            },
            $ownerRows,
        )));

        return $ownerIds === [] ? [] : $loader->loadManyToManyBatch(
            $this->targetClass,
            $this->pivotTable,
            $this->pivotOwnerColumn,
            $this->pivotTargetColumn,
            $ownerIds,
        );
    }

    public function buildLazyRelation(
        RelationLoaderInterface $loader,
        array $ownerRow,
        int|string|null $ownerId,
        ?array $preloaded = null,
    ): LazyRelation {
        if ($preloaded !== null) {
            return LazyRelation::resolved($preloaded[$ownerId] ?? []);
        }

        return LazyRelation::lazy(
            fn (): array => $ownerId === null ? [] : $loader->loadManyToMany(
                $this->targetClass,
                $this->pivotTable,
                $this->pivotOwnerColumn,
                $this->pivotTargetColumn,
                $ownerId,
            )
        );
    }
}