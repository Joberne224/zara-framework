<?php
// Zara\Cache\Support\TtlNormalizer.php
declare(strict_types=1);

namespace Zara\Cache\Support;

final class TtlNormalizer
{
    public static function toSeconds(null|int|\DateInterval $ttl): ?int
    {
        if ($ttl === null || is_int($ttl)) {
            return $ttl;
        }

        $now = new \DateTimeImmutable();

        return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
    }
}