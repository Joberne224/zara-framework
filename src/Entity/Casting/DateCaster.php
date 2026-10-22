<?php
declare(strict_types=1);

namespace Zara\Entity\Casting;

use DateTimeImmutable;
use DateTimeInterface;

/** Maps a SQL DATE column ("Y-m-d") to a DateTimeImmutable. */
final class DateCaster implements ValueCasterInterface
{
    private const FORMAT = 'Y-m-d';

    public function hydrate(mixed $rawValue): ?DateTimeImmutable
    {
        if ($rawValue === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!' . self::FORMAT, (string) $rawValue);

        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new \InvalidArgumentException(sprintf('Invalid date value "%s".', (string) $rawValue));
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
