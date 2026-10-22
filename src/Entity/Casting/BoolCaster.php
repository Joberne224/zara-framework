<?php
declare(strict_types=1);

namespace Zara\Entity\Casting;

/** Maps a SQL TINYINT(1) column to a bool. */
final class BoolCaster implements ValueCasterInterface
{
    public function hydrate(mixed $rawValue): ?bool
    {
        if ($rawValue === null) {
            return null;
        }

        return (bool) (int) $rawValue;
    }

    public function extract(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        assert(is_bool($value));

        return $value ? 1 : 0;
    }
}