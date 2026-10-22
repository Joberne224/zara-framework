<?php
// Zara\Entity\Relation\RelationLoaderInterface.php
declare(strict_types=1);

namespace Zara\Entity\Relation\Interfaces;

interface RelationLoaderInterface
{
    public function loadOne(string $targetClass, int|string $id): ?object;

    /** @return object[] */
    public function loadMany(string $targetClass, string $foreignKey, int|string $ownerId): array;

    /** @return object[] */
    public function loadManyToMany(
        string $targetClass,
        string $pivotTable,
        string $pivotOwnerColumn,
        string $pivotTargetColumn,
        int|string $ownerId,
    ): array;
}