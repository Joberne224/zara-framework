<?php
declare(strict_types=1);

namespace Zara\Cache\Interfaces;

/** Backend-specific expired-entry maintenance for development tooling. */
interface CacheMaintenanceInterface
{
    /** Returns null when expiration is managed natively or cannot be counted. */
    public function getExpiredEntryCount(): ?int;

    /** Returns null when expiration is managed natively and no purge is needed. */
    public function cleanupExpired(): ?int;
}
