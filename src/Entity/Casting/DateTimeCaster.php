<?php
declare(strict_types=1);

namespace Zara\Entity\Casting;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Maps a SQL DATETIME/TIMESTAMP column to a DateTimeImmutable. Accepts an
 * optional fractional part — MySQL DATETIME(fsp) and PostgreSQL both return
 * e.g. "2024-01-02 03:04:05.123456"; the microseconds are preserved in the
 * object and truncated back to seconds on extract().
 */
final class DateTimeCaster implements ValueCasterInterface
{
    private const FORMAT = 'Y-m-d H:i:s';

    public function hydrate(mixed $rawValue): ?DateTimeImmutable
    {
        if ($rawValue === null) {
            return null;
        }

        $raw = (string) $rawValue;
        $format = str_contains($raw, '.') ? self::FORMAT . '.u' : self::FORMAT;

        $date = DateTimeImmutable::createFromFormat($format, $raw);

        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new \InvalidArgumentException(sprintf('Invalid datetime value "%s".', $raw));
        }

        return $date;
    }

    public function extract(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        assert($value instanceof DateTimeInterface);

        return $value->format(self::FORMAT);
    }
}
