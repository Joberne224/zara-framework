<?php
// Zara\Entity\Relation\OneToManyMetadata.php
declare(strict_types=1);

namespace Zara\Entity\Relation;

use Zara\Entity\Relation\Interfaces\RelationBatchLoaderInterface;
use Zara\Entity\Relation\Interfaces\RelationLoaderInterface;
use Zara\Entity\Relation\Interfaces\RelationMetadataInterface;

final class OneToManyMetadata implements RelationMetadataInterface
{
    public function __construct(
        private readonly string $targetClass,
        public readonly string $foreignKey,
    ) {
    }

    public function targetClass(): string
    {
        return $this->targetClass;
    }

    public function preload(RelationBatchLoaderInterface $loader, array $ownerRows, string $ownerIdColumn): array
    {
        $ownerIds = array_values(array_unique(array_map(
            // preload() is called from hydrateAll() with rows from ANY query
            // the caller supplies, including custom repository queries — not
            // guaranteed to select every column. A missing owner id column
            // would otherwise surface as a silent "Undefined array key"
            // warning instead of a clear, actionable error.
            function (array $row) use ($ownerIdColumn): int|string {
                return $row[$ownerIdColumn]
                    ?? throw new \InvalidArgumentException(sprintf(
                        'Cannot preload relation targeting "%s": a row is missing the owner id column "%s". '
                            . 'Make sure any custom query passed to hydrateAll()/preloadRelations() selects it.',
                        $this->targetClass,
                        $ownerIdColumn,
                    ));
            },
            $ownerRows,
        )));

        return $ownerIds === [] ? [] : $loader->loadManyBatch($this->targetClass, $this->foreignKey, $ownerIds);
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
            fn(): array => $ownerId === null ? [] : $loader->loadMany($this->targetClass, $this->foreignKey, $ownerId)
        );
    }
}