<?php
//File: zara-framework/src/Database/Schema/Grammar/Mysql/MysqlGrammar.php
declare(strict_types=1);

namespace Zara\Database\Schema\Grammar\Mysql;

use Zara\Database\Schema\Blueprint;
use Zara\Database\Schema\ForeignKeyDefinition;
use Zara\Database\Schema\IndexDefinition;
use Zara\Database\Schema\SchemaGrammarInterface;

/**
 * Indexes and foreign keys are declared inside the CREATE TABLE itself
 * (KEY/UNIQUE KEY, FOREIGN KEY), the idiomatic MySQL syntax, unlike
 * Postgres/Sqlite where a separate CREATE INDEX is more common.
 */
final class MysqlGrammar implements SchemaGrammarInterface
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

        foreach ($blueprint->getIndexes() as $index) {
            $parts[] = $this->indexClause($index);
        }

        foreach ($blueprint->getForeignKeys() as $foreignKey) {
            $parts[] = $this->foreignKeyClause($foreignKey);
        }

        $sql = sprintf(
            "CREATE TABLE %s%s (\n    %s\n)",
            $ifNotExists ? 'IF NOT EXISTS ' : '',
            $this->quote($blueprint->table),
            implode(",\n    ", $parts),
        );

        return [$sql];
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

        foreach ($blueprint->getIndexes() as $index) {
            $statements[] = "ALTER TABLE {$table} ADD " . $this->indexClause($index);
        }

        foreach ($blueprint->getForeignKeys() as $foreignKey) {
            $statements[] = "ALTER TABLE {$table} ADD " . $this->foreignKeyClause($foreignKey);
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

    /**
     * MySQL commits implicitly on every DDL statement, so a surrounding
     * transaction cannot be rolled back (and is closed behind our back).
     */
    public function supportsTransactionalDdl(): bool
    {
        return false;
    }

    private function indexClause(IndexDefinition $index): string
    {
        $columns = implode(', ', array_map($this->quote(...), $index->columns));
        $keyword = $index->unique ? 'UNIQUE KEY' : 'KEY';

        return sprintf('%s %s (%s)', $keyword, $this->quote($index->name), $columns);
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
        return '`' . \Zara\Database\Schema\SchemaIdentifier::validate($identifier) . '`';
    }
}
