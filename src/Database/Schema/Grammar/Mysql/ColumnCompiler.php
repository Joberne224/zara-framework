<?php
//File: zara-framework/src/Database/Schema/Grammar/Mysql/ColumnCompiler.php
declare(strict_types=1);

namespace Zara\Database\Schema\Grammar\Mysql;

use Zara\Database\Schema\ColumnDefinition;
use Zara\Database\Schema\ColumnType;

final class ColumnCompiler
{
    public function compile(ColumnDefinition $column): string
    {
        $sql = $this->quote($column->name) . ' ' . $this->type($column);

        if ($column->isUnsigned() && in_array($column->type, [ColumnType::Integer, ColumnType::BigInteger], true)) {
            $sql .= ' UNSIGNED';
        }

        $sql .= $column->isNullable() ? ' NULL' : ' NOT NULL';

        if ($column->isAutoIncrement()) {
            if (!in_array($column->type, [ColumnType::Id, ColumnType::Integer, ColumnType::BigInteger], true)) {
                throw new \LogicException(sprintf(
                    'Column "%s": MySQL AUTO_INCREMENT requires an integer column (id/integer/bigInteger).',
                    $column->name
                ));
            }
            $sql .= ' AUTO_INCREMENT PRIMARY KEY';
        }

        if ($column->hasDefault()) {
            $sql .= ' DEFAULT ' . $this->formatDefault($column->getDefault());
        }

        if ($column->isUnique() && !$column->isAutoIncrement()) {
            $sql .= ' UNIQUE';
        }

        return $sql;
    }

    private function type(ColumnDefinition $column): string
    {
        return match ($column->type) {
            ColumnType::Id => 'BIGINT',
            ColumnType::String => "VARCHAR({$column->length})",
            ColumnType::Text => 'TEXT',
            ColumnType::Integer => 'INT',
            ColumnType::BigInteger => 'BIGINT',
            ColumnType::Boolean => 'TINYINT(1)',
            ColumnType::Decimal => "DECIMAL({$column->precision},{$column->scale})",
            ColumnType::DateTime => 'DATETIME',
            ColumnType::Date => 'DATE',
            ColumnType::Json => 'JSON',
        };
    }

    private function formatDefault(mixed $value): string
    {
        return \Zara\Database\Schema\SchemaDefaultValue::format($value);
    }

    private function quote(string $identifier): string
    {
        return '`' . \Zara\Database\Schema\SchemaIdentifier::validate($identifier) . '`';
    }
}
