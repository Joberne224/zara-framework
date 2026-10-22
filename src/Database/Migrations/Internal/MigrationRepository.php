<?php

declare(strict_types=1);

namespace Zara\Database\Migrations\Internal;

use Zara\Database\DatabaseInterface;
use Zara\Database\Schema\Blueprint;
use Zara\Database\Schema\Schema;

/**
 * Tracks which migrations have already been applied in the database.
 *
 * A "batch" groups all migrations run during a single migrate() call:
 * rollback() undoes the entire last batch at once, rather than only the last
 * individual migration. This is the database equivalent of undoing a group
 * of changes instead of a single action.
 *
 * ensureTableExists() passe maintenant par Schema (Zara\Database\Schema),
 * This keeps the repository portable across all three drivers and removes
 * the MySQL-only limitation of the raw DDL used before the schema builder
 * existed. The remaining query/insert/delete/fetchColumn operations use
 * ordinary DML through DatabaseInterface, which is already driver-agnostic.
 */
final class MigrationRepository
{
    private const TABLE = 'zara_migrations';

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly Schema $schema,
    ) {
    }

    public function ensureTableExists(): void
    {
        $this->schema->createIfNotExists(self::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->string('migration')->unique();
            $table->integer('batch');
            $table->dateTime('applied_at');
        });
    }

    /**
     * @return list<string>
     */
    public function getApplied(): array
    {
        $rows = $this->db->query('SELECT migration FROM ' . self::TABLE . ' ORDER BY id ASC');

        return array_map(static fn(array $row): string => $row['migration'], $rows);
    }

    public function getNextBatch(): int
    {
        $lastBatch = $this->db->fetchColumn('SELECT MAX(batch) FROM ' . self::TABLE);

        return ((int) $lastBatch) + 1;
    }

    public function record(string $migration, int $batch): void
    {
        $this->db->insert(self::TABLE, [
            'migration'  => $migration,
            'batch'      => $batch,
            'applied_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return list<string> Sorted from newest to oldest (rollback order)
     */
    public function getMigrationsForLastBatch(): array
    {
        $lastBatch = $this->db->fetchColumn('SELECT MAX(batch) FROM ' . self::TABLE);

        if ($lastBatch === null) {
            return [];
        }

        $rows = $this->db->query(
            'SELECT migration FROM ' . self::TABLE . ' WHERE batch = ? ORDER BY id DESC',
            [$lastBatch],
        );

        return array_map(static fn(array $row): string => $row['migration'], $rows);
    }

    public function remove(string $migration): void
    {
        $this->db->delete(self::TABLE, 'migration = ?', [$migration]);
    }
}
