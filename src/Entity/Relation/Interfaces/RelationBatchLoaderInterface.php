<?php
// Zara\Entity\Relation\RelationBatchLoaderInterface.php
declare(strict_types=1);

namespace Zara\Entity\Relation\Interfaces;

interface RelationBatchLoaderInterface
{
    /** @return array<int|string, object[]> groupé par id du propriétaire */
    public function loadManyBatch(string $targetClass, string $foreignKey, array $ownerIds): array;

    /** @return array<int|string, object> groupé par id de la cible */
    public function loadOneBatch(string $targetClass, array $ids): array;

    /**
     * @param array<int, int|string> $ownerIds
     * @return array<int|string, object[]> groupé par id du propriétaire
     */
    public function loadManyToManyBatch(
        string $targetClass,
        string $pivotTable,
        string $pivotOwnerColumn,
        string $pivotTargetColumn,
        array $ownerIds,
    ): array;
}