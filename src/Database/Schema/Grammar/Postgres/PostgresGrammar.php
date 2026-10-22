<?php
//File: zara-framework/src/Database/Schema/Grammar/Postgres/PostgresGrammar.php
declare(strict_types=1);

namespace Zara\Database\Schema\Grammar\Postgres;

use Zara\Database\Schema\Blueprint;
use Zara\Database\Schema\ForeignKeyDefinition;
use Zara\Database\Schema\IndexDefinition;
use Zara\Database\Schema\SchemaGrammarInterface;

/**
 * Unlike MySQL, indexes are created with separate CREATE INDEX statements
 * rather than inline in CREATE TABLE. This is idiomatic Postgres syntax and
 * avoids special handling for composite columns. Foreign keys remain inline
 * during table creation, as standard SQL constraints natively supported by
 * Postgres.
 */
final class PostgresGrammar implements SchemaGrammarInterface
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
        $statements = [];
        $table = $this->quote($blueprint->table);

        foreach ($blueprint->getColumns() as $column) {
            $statements[] = "ALTER TABLE {$table} ADD COLUMN " . $this->columnCompiler->compile($column);
        }

        foreach ($blueprint->getDroppedColumns() as $columnName) {
            $statements[] = "ALTER TABLE {$table} DROP COLUMN " . $this->quote($columnName);
        }

        foreach ($blueprint->getForeignKeys() as $foreignKey) {
            $statements[] = "ALTER TABLE {$table} ADD " . $this->foreignKeyClause($foreignKey);
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
     * PostgreSQL can roll back DDL statements inside a transaction.
     */
    public function supportsTransactionalDdl(): bool
    {
        return true;
    }
}
