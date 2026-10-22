<?php
declare(strict_types=1);

namespace Zara\Entity\Relation;

use Zara\Database\DatabaseInterface;
use Zara\Entity\Relation\Interfaces\PivotWriterInterface;
use Zara\Database\Query\QueryBuilder;

final class PivotWriter implements PivotWriterInterface
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function attach(
        string $pivotTable,
        string $ownerColumn,
        string $targetColumn,
        int|string $ownerId,
        int|string $targetId,
    ): void {
        $exists = QueryBuilder::table($this->db, $pivotTable)
            ->where($ownerColumn, '=', $ownerId)
            ->where($targetColumn, '=', $targetId)
            ->exists();

        if ($exists) {
            return;
        }

        QueryBuilder::table($this->db, $pivotTable)->insert([
            $ownerColumn => $ownerId,
            $targetColumn => $targetId,
        ]);
    }

    public function detach(
        string $pivotTable,
        string $ownerColumn,
        string $targetColumn,
        int|string $ownerId,
        int|string $targetId,
    ): void {
        QueryBuilder::table($this->db, $pivotTable)
            ->where($ownerColumn, '=', $ownerId)
            ->where($targetColumn, '=', $targetId)
            ->delete();
    }

    public function sync(
        string $pivotTable,
        string $ownerColumn,
        string $targetColumn,
        int|string $ownerId,
        array $targetIds,
    ): void {
        $targetIds = array_values(array_unique($targetIds));

        $this->db->transaction(function () use (
            $pivotTable,
            $ownerColumn,
            $targetColumn,
            $ownerId,
            $targetIds,
        ): void {
            // Delete-then-reinsert rather than diffing the existing set:
            // simpler, and the whole thing is wrapped in a transaction
            // anyway, so there's no window where the owner has zero
            // associations from another connection's point of view.
            QueryBuilder::table($this->db, $pivotTable)
                ->where($ownerColumn, '=', $ownerId)
                ->delete();

            foreach ($targetIds as $targetId) {
                QueryBuilder::table($this->db, $pivotTable)->insert([
                    $ownerColumn => $ownerId,
                    $targetColumn => $targetId,
                ]);
            }
        });
    }
}