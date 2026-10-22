<?php
//File: zara-framework/src/Database/Schema/Grammar/Sqlite/SqliteGrammar.php
declare(strict_types=1);

namespace Zara\Database\Schema\Grammar\Sqlite;

use Zara\Database\Schema\Blueprint;
use Zara\Database\Schema\Exception\UnsupportedSchemaOperationException;
use Zara\Database\Schema\ForeignKeyDefinition;
use Zara\Database\Schema\IndexDefinition;
use Zara\Database\Schema\SchemaGrammarInterface;

/**
 * SQLite-specific limitations are acknowledged rather than hidden:
 *
 * 1. Foreign keys can only be declared when creating a table. SQLite does
 *    not support ALTER TABLE ADD FOREIGN KEY. Blueprint::foreign() in a
 *    Schema::table() context therefore throws UnsupportedSchemaOperationException
 *    instead of producing SQL silently ignored by the driver.
 * 2. DROP COLUMN requires SQLite >= 3.35.0 (2021), which is widely available
 *    but should be checked if the project targets an older environment.
 * 3. ALTER TABLE ADD COLUMN cannot add a PRIMARY KEY/auto-increment column,
 *    nor a NOT NULL column without a default. Both are rejected up front in
 *    compileAlter() with an actionable message instead of a cryptic driver
 *    error at run time.
 *
 * Foreign key constraint enforcement also depends on PRAGMA foreign_keys,
 * configured at the connection level and outside the scope of the DDL
 * compiled here.
 */
final class SqliteGrammar implements SchemaGrammarInterface
{
    public function __construct(private readonly ColumnCompiler $columnCompiler = new ColumnCompiler())
    {
    }

    public function compileCreate(Blueprint $blueprint, bool $ifNotExists = false): array
    {
        $parts = array_map(
            fn($column) => $this->columnCompiler->compile($column),
            $blueprint->getColumns(),
        );

        foreach ($blueprint->getForeignKeys() as $foreignKey) {
            $parts[] = $this->foreignKeyClause($foreignKey);
        }

        $statements = [sprintf(
            "CREATE TABLE %s%s (\n    %s\n)",
            $ifNotExists ? 'IF NOT EXISTS ' : '',
            $this->quote($blueprint->table),
            implode(",\n    ", $parts),
        )];

        foreach ($blueprint->getIndexes() as $index) {
            $statements[] = $this->indexStatement($blueprint->table, $index, $ifNotExists);
        }

        return $statements;
    }

    public function compileAlter(Blueprint $blueprint): array
    {
        if ($blueprint->getForeignKeys() !== []) {
            throw new UnsupportedSchemaOperationException(
                "SQLite does not support adding a foreign key through ALTER TABLE "
                    . "(table '{$blueprint->table}'); it must be declared when the table is created."
            );
        }

        $statements = [];
        $table = $this->quote($blueprint->table);

        foreach ($blueprint->getColumns() as $column) {
            // SQLite rejects both of these at the driver level with cryptic
            // errors; failing here turns them into the actionable message the
            // caller can actually fix ("give the column a default").
            if ($column->isAutoIncrement()) {
                throw new UnsupportedSchemaOperationException(
                    "SQLite cannot add column \"{$column->name}\" through ALTER TABLE: an "
                        . 'auto-increment column is a PRIMARY KEY, and SQLite cannot add a '
                        . 'PRIMARY KEY column after creation. Declare it in create() instead.'
                );
            }
            if (!$column->isNullable() && !$column->hasDefault()) {
                throw new UnsupportedSchemaOperationException(
                    "SQLite cannot add column \"{$column->name}\" as NOT NULL without a "
                        . 'default value: existing rows would have nothing to hold. Give the '
                        . 'column default() or make it nullable().'
                );
            }

            $statements[] = "ALTER TABLE {$table} ADD COLUMN " . $this->columnCompiler->compile($column);
        }

        foreach ($blueprint->getDroppedColumns() as $columnName) {
            // Requires SQLite >= 3.35.0.
            $statements[] = "ALTER TABLE {$table} DROP COLUMN " . $this->quote($columnName);
        }

        foreach ($blueprint->getIndexes() as $index) {
            $statements[] = $this->indexStatement($blueprint->table, $index);
        }

        return $statements;
    }

    public function compileDrop(string $table): string
    {
        return 'DROP TABLE ' . $this->quote($table);
    }

    public function compileDropIfExists(string $table): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->quote($table);
    }

    private function indexStatement(string $table, IndexDefinition $index, bool $ifNotExists = false): string
    {
        $columns = implode(', ', array_map($this->quote(...), $index->columns));
        $keyword = $index->unique ? 'CREATE UNIQUE INDEX' : 'CREATE INDEX';

        return sprintf(
            '%s %s%s ON %s (%s)',
            $keyword,
            $ifNotExists ? 'IF NOT EXISTS ' : '',
            $this->quote($index->name),
            $this->quote($table),
            $columns,
        );
    }

    private function foreignKeyClause(ForeignKeyDefinition $foreignKey): string
    {
        return sprintf(
            'FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s ON UPDATE %s',
            $this->quote($foreignKey->column),
            $this->quote($foreignKey->referencedTable),
            $this->quote($foreignKey->referencedColumn),
            $foreignKey->onDelete,
            $foreignKey->onUpdate,
        );
    }

    private function quote(string $identifier): string
    {
        return '"' . \Zara\Database\Schema\SchemaIdentifier::validate($identifier) . '"';
    }

    /**
     * SQLite can roll back DDL statements inside a transaction.
     */
    public function supportsTransactionalDdl(): bool
    {
        return true;
    }
}
