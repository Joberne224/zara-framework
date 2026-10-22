<?php
declare(strict_types=1);

namespace Zara\Cache\Interfaces;

/** Read-only cache metadata intended for development tooling. */
interface CacheDiagnosticsInterface
{
    /**
     * @return array{
     *   entries:int,
     *   expired:int,
     *   storage_bytes:int,
     *   items:list<array{fingerprint:string,expires_in:int|null,size_bytes:int}>
     * }
     */
    public function getProfilerSnapshot(int $limit = 100): array;
}
