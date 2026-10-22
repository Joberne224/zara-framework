<?php
declare(strict_types=1);

namespace Zara\Entity\Relation;

use Zara\Entity\Relation\Interfaces\RelationBatchLoaderInterface;
use Zara\Entity\Relation\Interfaces\RelationLoaderInterface;
use Zara\Entity\Relation\Interfaces\RelationMetadataInterface;

final class ManyToOneMetadata implements RelationMetadataInterface
{
    public function __construct(
        private readonly string $targetClass,
        public readonly string $localKey,
    ) {
    }

    public function targetClass(): string
    {
        return $this->targetClass;
    }

    public function preload(RelationBatchLoaderInterface $loader, array $ownerRows, string $ownerIdColumn): array
    {
        $foreignIds = array_values(array_unique(array_filter(
            array_map(
                fn(array $row): mixed => $this->foreignKeyOf($row),
                $ownerRows,
            ),
            // array_filter() with no callback drops every falsy value (0, '',
            // false, null) — a foreign key of 0 or "0" would be silently
            // excluded from the batch and fall back to a per-row lazy query
            // instead. Only null (a genuinely empty foreign key) should drop.
            static fn(mixed $id): bool => $id !== null,
        )));

        return $foreignIds === [] ? [] : $loader->loadOneBatch($this->targetClass, $foreignIds);
    }

    public function buildLazyRelation(
        RelationLoaderInterface $loader,
        array $ownerRow,
        int|string|null $ownerId,
        ?array $preloaded = null,
    ): LazyRelation {
        $foreignId = $this->foreignKeyOf($ownerRow);

        if ($preloaded !== null) {
            return LazyRelation::resolved($foreignId === null ? null : ($preloaded[$foreignId] ?? null));
        }

        return LazyRelation::lazy(
            fn(): ?object => $foreignId === null ? null : $loader->loadOne($this->targetClass, $foreignId)
        );
    }

    /**
     * Distinguishes an ABSENT key (a custom query that did not select it, or
     * an unmapped column missing from extract()) from a present-but-null one
     * (a legitimately empty relation). Treating both as null would silently
     * resolve the relation to null and hide the problem.
     *
     * @param array<string, mixed> $row
     */
    private function foreignKeyOf(array $row): mixed
    {
        if (!array_key_exists($this->localKey, $row)) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot resolve relation targeting "%s": a row is missing the foreign key column "%s". '
                    . 'Make sure any custom query passed to hydrateAll()/preloadRelations() selects it, '
                    . 'and that the column is mapped with #[Column] on the owning entity.',
                $this->targetClass,
                $this->localKey,
            ));
        }

        return $row[$this->localKey];
    }
}