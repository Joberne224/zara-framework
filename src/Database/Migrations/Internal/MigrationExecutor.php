<?php

declare(strict_types=1);

namespace Zara\Database\Migrations\Internal;

use Zara\Database\DatabaseInterface;
use Zara\Database\Migrations\Migration;
use Zara\Database\Schema\Schema;

/**
 * Runs a migration, wrapped in a transaction only when the database can roll
 * back DDL (PostgreSQL, SQLite). On MySQL every CREATE/ALTER/DROP commits
 * implicitly, so a transaction would be closed behind our back and the final
 * commit() would fail.
 */
final class MigrationExecutor
{
    public function up(Migration $migration, DatabaseInterface $db, Schema $schema, ?callable $after = null): void
    {
        $this->run(
            static fn(DatabaseInterface $db) => $migration->up($db, $schema),
            $db,
            $schema,
            $after,
        );
    }

    public function down(Migration $migration, DatabaseInterface $db, Schema $schema, ?callable $after = null): void
    {
        $this->run(
            static fn(DatabaseInterface $db) => $migration->down($db, $schema),
            $db,
            $schema,
            $after,
        );
    }

    private function run(callable $work, DatabaseInterface $db, Schema $schema, ?callable $after): void
    {
        if (!$schema->supportsTransactionalDdl()) {
            $work($db);
            $after?->__invoke();

            return;
        }

        $db->transaction(static function (DatabaseInterface $db) use ($work, $after): void {
            $work($db);
            $after?->__invoke();
        });
    }
}
