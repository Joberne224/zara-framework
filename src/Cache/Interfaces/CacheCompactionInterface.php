<?php
declare(strict_types=1);

namespace Zara\Cache\Interfaces;

/** Optional storage compaction for cache backends that support it. */
interface CacheCompactionInterface
{
    public function compact(): void;
}
