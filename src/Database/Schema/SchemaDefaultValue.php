<?php
declare(strict_types=1);

namespace Zara\Database\Schema;

/** Formats literal schema defaults without accepting raw SQL expressions. */
final class SchemaDefaultValue
{
    public static function format(mixed $value, bool $booleanAsWords = false): string
    {
        if ($value === null) return 'NULL';
        if (is_bool($value)) return $booleanAsWords ? ($value ? 'TRUE' : 'FALSE') : ($value ? '1' : '0');
        if (is_int($value)) return (string) $value;
        if (is_float($value)) {
            if (!is_finite($value)) throw new \InvalidArgumentException('A numeric schema default must be finite.');
            return (string) $value;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Schema defaults must be null, scalar numbers, booleans, or strings.');
        }
        if (str_contains($value, '\\') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException('Schema default strings cannot contain backslashes or control characters.');
        }

        return "'" . str_replace("'", "''", $value) . "'";
    }

    private function __construct() {}
}
