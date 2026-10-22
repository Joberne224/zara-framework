<?php
declare(strict_types=1);

namespace Zara\Entity\Casting;

/** Maps a SQL DECIMAL column (returned as a string by PDO) to a float. */
final class FloatCaster implements ValueCasterInterface
{
    public function hydrate(mixed $rawValue): ?float
    {
        if ($rawValue === null) {
            return null;
        }

        if (!is_numeric($rawValue)) {
            throw new \InvalidArgumentException(sprintf('Non-numeric value "%s".', (string) $rawValue));
        }

        return (float) $rawValue;
    }

    public function extract(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        assert(is_float($value) || is_int($value));

        // Avoid imposing a two-decimal scale on columns with greater precision.
        // sprintf() uses a decimal point regardless of the process locale.
        return sprintf('%.17g', (float) $value);
    }
}
