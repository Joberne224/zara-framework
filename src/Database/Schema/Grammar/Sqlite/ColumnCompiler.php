<?php
//File: zara-framework/src/Database/Schema/Grammar/Sqlite/ColumnCompiler.php

declare(strict_types=1);

namespace Zara\Database\Schema\Grammar\Sqlite;

use Zara\Database\Schema\ColumnDefinition;
use Zara\Database\Schema\ColumnType;

final class ColumnCompiler
{
    public function compile(ColumnDefinition $column): string
    {
        if ($column->isAutoIncrement()) {
            if (!in_array($column->type, [ColumnType::Id, ColumnType::Integer], true)) {
                throw new \LogicException(sprintf(
                    'Column "%s": SQLite only supports auto-increment on INTEGER columns '
                        . '("INTEGER PRIMARY KEY AUTOINCREMENT"); use id() or integer(). '
                        . 'A BIGINT auto-increment column is not representable on SQLite.',
                    $column->name
                ));
            }

            // SQLite requires this exact form to create a true alias for the
            // auto-incrementing rowid; any variation (BIGINT, adding NOT NULL,
            // etc.) loses this property. Defaults/nullable/unique would break
            // that form, so they are ignored here rather than emitted.
            return $this->quote($column->name) . ' INTEGER PRIMARY KEY AUTOINCREMENT';
        }

        $sql = $this->quote($column->name) . ' ' . $this->type($column);
        $sql .= $column->isNullable() ? ' NULL' : ' NOT NULL';

        if ($column->hasDefault()) {
            $sql .= ' DEFAULT ' . $this->formatDefault($column->getDefault());
        }

        if ($column->isUnique()) {
            $sql .= ' UNIQUE';
        }

        // SQLite has no "UNSIGNED" equivalent (dynamic typing), so this
        // modifier is silently ignored, as it is on Postgres.

        return $sql;
    }

    private function type(ColumnDefinition $column): string
    {
        return match ($column->type) {
            ColumnType::Id => 'INTEGER',
            ColumnType::String => "VARCHAR({$column->length})",
            ColumnType::Text => 'TEXT',
            ColumnType::Integer => 'INTEGER',
            ColumnType::BigInteger => 'BIGINT',
            ColumnType::Boolean => 'BOOLEAN',
            ColumnType::Decimal => "DECIMAL({$column->precision},{$column->scale})",
            ColumnType::DateTime => 'DATETIME',
            ColumnType::Date => 'DATE',
            ColumnType::Json => 'TEXT',
        };
    }

    private function formatDefault(mixed $value): string
    {
        return \Zara\Database\Schema\SchemaDefaultValue::format($value);
    }

    private function quote(string $identifier): string
    {
        return '"' . \Zara\Database\Schema\SchemaIdentifier::validate($identifier) . '"';
    }
}
