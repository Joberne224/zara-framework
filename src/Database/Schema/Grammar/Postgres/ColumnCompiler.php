<?php
//File: zara-framework/src/Database/Schema/Grammar/Postgres/ColumnCompiler.php

declare(strict_types=1);

namespace Zara\Database\Schema\Grammar\Postgres;

use Zara\Database\Schema\ColumnDefinition;
use Zara\Database\Schema\ColumnType;

final class ColumnCompiler
{
    public function compile(ColumnDefinition $column): string
    {
        $sql = $this->quote($column->name) . ' ' . $this->type($column);

        $sql .= $column->isNullable() ? ' NULL' : ' NOT NULL';

        if ($column->isAutoIncrement()) {
            // SERIAL/BIGSERIAL already implies NOT NULL and a sequence-backed
            // default; PRIMARY KEY mirrors what the MySQL grammar emits for
            // AUTO_INCREMENT columns, so the same Blueprint means the same
            // thing on both drivers.
            $sql .= ' PRIMARY KEY';
        }

        if ($column->hasDefault()) {
            $sql .= ' DEFAULT ' . $this->formatDefault($column->getDefault());
        }

        if ($column->isUnique() && !$column->isAutoIncrement()) {
            $sql .= ' UNIQUE';
        }

        // Postgres has no "UNSIGNED" equivalent, so this modifier is silently
        // ignored (see ColumnDefinition::unsigned()).

        return $sql;
    }

    private function type(ColumnDefinition $column): string
    {
        if ($column->isAutoIncrement()) {
            return match ($column->type) {
                ColumnType::Id, ColumnType::BigInteger => 'BIGSERIAL',
                ColumnType::Integer => 'SERIAL',
                default => throw new \LogicException(sprintf(
                    'Column "%s": only integer columns (id/integer/bigInteger) can be auto-incrementing on PostgreSQL.',
                    $column->name
                )),
            };
        }

        return match ($column->type) {
            ColumnType::Id => 'BIGSERIAL',
            ColumnType::String => "VARCHAR({$column->length})",
            ColumnType::Text => 'TEXT',
            ColumnType::Integer => 'INTEGER',
            ColumnType::BigInteger => 'BIGINT',
            ColumnType::Boolean => 'BOOLEAN',
            ColumnType::Decimal => "DECIMAL({$column->precision},{$column->scale})",
            ColumnType::DateTime => 'TIMESTAMP',
            ColumnType::Date => 'DATE',
            ColumnType::Json => 'JSONB',
        };
    }

    private function formatDefault(mixed $value): string
    {
        return \Zara\Database\Schema\SchemaDefaultValue::format($value, booleanAsWords: true);
    }

    private function quote(string $identifier): string
    {
        return '"' . \Zara\Database\Schema\SchemaIdentifier::validate($identifier) . '"';
    }
}
