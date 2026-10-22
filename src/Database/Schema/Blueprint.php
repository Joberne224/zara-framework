<?php
//File: zara-framework/src/Database/Schema/Blueprint.php
declare(strict_types=1);

namespace Zara\Database\Schema;

/**
 * Collects table intents (columns, indexes, foreign keys) without knowing
 * the final SQL. A SchemaGrammarInterface translates this Blueprint for a
 * given driver. It is used for both Schema::create() (all columns are added)
 * and Schema::table() in an ALTER context (declared columns become ADD COLUMN
 * and dropColumn() marks DROP COLUMN); the Grammar distinguishes these
 * contexts, not the Blueprint itself.
 */
final class Blueprint
{
    /** @var list<ColumnDefinition> */
    private array $columns = [];

    /** @var list<IndexDefinition> */
    private array $indexes = [];

    /** @var list<ForeignKeyDefinition> */
    private array $foreignKeys = [];

    /** @var list<string> */
    private array $droppedColumns = [];

    public function __construct(public readonly string $table)
    {
        SchemaIdentifier::validate($table);
    }

    /**
     * Auto-incrementing primary key column. Each Grammar maps it to its own
     * mechanism (AUTO_INCREMENT, BIGSERIAL,
     * INTEGER PRIMARY KEY AUTOINCREMENT...).
     */
    public function id(string $name = 'id'): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($name, ColumnType::Id))->autoIncrement();
    }

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($name, ColumnType::String, length: $length));
    }

    public function text(string $name): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($name, ColumnType::Text));
    }

    public function integer(string $name): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($name, ColumnType::Integer));
    }

    public function bigInteger(string $name): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($name, ColumnType::BigInteger));
    }

    public function boolean(string $name): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($name, ColumnType::Boolean));
    }

    public function decimal(string $name, int $precision = 10, int $scale = 2): ColumnDefinition
    {
        if ($precision < 1 || $scale < 0 || $scale > $precision) {
            throw new \InvalidArgumentException('Decimal precision must be positive and scale must be between zero and precision.');
        }
        return $this->addColumn(new ColumnDefinition($name, ColumnType::Decimal, precision: $precision, scale: $scale));
    }

    public function dateTime(string $name): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($name, ColumnType::DateTime));
    }

    public function date(string $name): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($name, ColumnType::Date));
    }

    public function json(string $name): ColumnDefinition
    {
        return $this->addColumn(new ColumnDefinition($name, ColumnType::Json));
    }

    /**
     * @param string|list<string> $columns
     */
    public function index(string|array $columns, ?string $name = null): void
    {
        $columns = (array) $columns;
        $this->indexes[] = new IndexDefinition(SchemaIdentifier::validate($name ?? $this->defaultIndexName($columns, 'index')), $this->validateColumns($columns), unique: false);
    }

    /**
     * @param string|list<string> $columns
     */
    public function uniqueIndex(string|array $columns, ?string $name = null): void
    {
        $columns = (array) $columns;
        $this->indexes[] = new IndexDefinition(SchemaIdentifier::validate($name ?? $this->defaultIndexName($columns, 'unique')), $this->validateColumns($columns), unique: true);
    }

    public function foreign(
        string $column,
        string $referencedTable,
        string $referencedColumn = 'id',
        string $onDelete = 'RESTRICT',
        string $onUpdate = 'RESTRICT',
    ): void {
        SchemaIdentifier::validate($column);
        SchemaIdentifier::validate($referencedTable);
        SchemaIdentifier::validate($referencedColumn);
        $this->foreignKeys[] = new ForeignKeyDefinition($column, $referencedTable, $referencedColumn, $onDelete, $onUpdate);
    }

    /**
     * Marks an existing column for removal (Schema::table() context only).
     */
    public function dropColumn(string $name): void
    {
        $this->droppedColumns[] = SchemaIdentifier::validate($name);
    }

    private function addColumn(ColumnDefinition $column): ColumnDefinition
    {
        SchemaIdentifier::validate($column->name);
        $this->columns[] = $column;

        return $column;
    }

    /** @param list<string> $columns @return list<string> */
    private function validateColumns(array $columns): array
    {
        if ($columns === []) {
            throw new \InvalidArgumentException('An index must include at least one column.');
        }
        return array_map(SchemaIdentifier::validate(...), $columns);
    }

    /**
     * @param list<string> $columns
     */
    private function defaultIndexName(array $columns, string $suffix): string
    {
        return strtolower($this->table . '_' . implode('_', $columns) . '_' . $suffix);
    }

    /** @return list<ColumnDefinition> */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /** @return list<IndexDefinition> */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    /** @return list<ForeignKeyDefinition> */
    public function getForeignKeys(): array
    {
        return $this->foreignKeys;
    }

    /** @return list<string> */
    public function getDroppedColumns(): array
    {
        return $this->droppedColumns;
    }
}
