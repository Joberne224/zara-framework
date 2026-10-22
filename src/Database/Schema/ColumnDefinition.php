<?php
//File: zara-framework/src/Database/Schema/ColumnDefinition.php
declare(strict_types=1);

namespace Zara\Database\Schema;

/**
 * Describes a column to create. Structural properties (name, type, length,
 * precision, scale) are fixed at construction; modifiers (nullable, default,
 * unique, unsigned, autoIncrement) remain mutable and chainable, allowing
 * fluent Blueprint definitions such as:
 * $table->string('email')->unique()->nullable().
 */
final class ColumnDefinition
{
    private bool $nullable = false;
    private bool $hasDefault = false;
    private mixed $default = null;
    private bool $unique = false;
    private bool $unsigned = false;
    private bool $autoIncrement = false;

    public function __construct(
        public readonly string $name,
        public readonly ColumnType $type,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
    ) {
        if ($type === ColumnType::String && ($length === null || $length < 1)) {
            throw new \InvalidArgumentException('String column length must be positive.');
        }
        if ($type === ColumnType::Decimal && ($precision === null || $precision < 1 || $scale === null || $scale < 0 || $scale > $precision)) {
            throw new \InvalidArgumentException('Decimal precision must be positive and scale must be between zero and precision.');
        }
    }

    public function nullable(bool $nullable = true): static
    {
        $this->nullable = $nullable;

        return $this;
    }

    public function default(mixed $value): static
    {
        $this->hasDefault = true;
        $this->default = $value;

        return $this;
    }

    public function unique(bool $unique = true): static
    {
        $this->unique = $unique;

        return $this;
    }

    /**
     * Has no effect on Postgres/SQLite, which have no concept of an unsigned
     * numeric column. Each Grammar ignores this modifier if its driver does
     * not support it, rather than raising an error.
     */
    public function unsigned(bool $unsigned = true): static
    {
        $this->unsigned = $unsigned;

        return $this;
    }

    public function autoIncrement(bool $autoIncrement = true): static
    {
        $this->autoIncrement = $autoIncrement;

        return $this;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    public function getDefault(): mixed
    {
        return $this->default;
    }

    public function isUnique(): bool
    {
        return $this->unique;
    }

    public function isUnsigned(): bool
    {
        return $this->unsigned;
    }

    public function isAutoIncrement(): bool
    {
        return $this->autoIncrement;
    }
}
