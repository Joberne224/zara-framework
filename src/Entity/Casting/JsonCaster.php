<?php
declare(strict_types=1);

namespace Zara\Entity\Casting;

/** Maps a SQL JSON column to a PHP array. */
final class JsonCaster implements ValueCasterInterface
{
    public function hydrate(mixed $rawValue): ?array
    {
        if ($rawValue === null) {
            return null;
        }

        if (is_array($rawValue)) {
            return $rawValue;
        }

        $decoded = json_decode((string) $rawValue, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('JSON column must contain an object or an array.');
        }

        return $decoded;
    }

    public function extract(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        assert(is_array($value));

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}