<?php

declare(strict_types=1);

namespace Zara\Cache\Driver;

use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use RedisException;
use Zara\Cache\Interfaces\CacheDiagnosticsInterface;
use Zara\Cache\Interfaces\CacheMaintenanceInterface;
use Zara\Cache\Support\CircuitBreakerFile;

/**
 * Decorates a primary cache (RedisCache) with a secondary, disk-backed
 * cache (FilesystemCache) used whenever the primary is unreachable.
 *
 * Option B, as agreed: writes always go to both, so the filesystem copy
 * stays current while Redis is healthy — no gap to backfill once Redis
 * comes back. Reads prefer the primary; the secondary is only consulted
 * when the primary throws or the circuit breaker is already open.
 *
 * The breaker (CircuitBreakerFile) persists across requests. Without it,
 * every single PHP-FPM request would pay a full Redis connection timeout
 * for as long as Redis stays down — the breaker makes that cost "once
 * per cooldown window" instead of "once per request."
 */
final class FallbackCache implements CacheInterface, CacheDiagnosticsInterface, CacheMaintenanceInterface
{
    public function __construct(
        private readonly CacheInterface $primary,
        private readonly CacheInterface $secondary,
        private readonly CircuitBreakerFile $breaker,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->readThrough(
            fn (CacheInterface $c) => $c->get($key, $default),
            fn () => $this->secondary->get($key, $default),
        );
    }

    public function has(string $key): bool
    {
        return $this->readThrough(
            fn (CacheInterface $c) => $c->has($key),
            fn () => $this->secondary->has($key),
        );
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return $this->readThrough(
            fn (CacheInterface $c) => $c->getMultiple($keys, $default),
            fn () => $this->secondary->getMultiple($keys, $default),
        );
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        return $this->writeThrough(fn (CacheInterface $c) => $c->set($key, $value, $ttl));
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        // The operation is executed against both stores. Materialize once so
        // generators are not exhausted after the secondary write.
        $values = is_array($values) ? $values : iterator_to_array($values);

        return $this->writeThrough(fn (CacheInterface $c) => $c->setMultiple($values, $ttl));
    }

    public function delete(string $key): bool
    {
        return $this->writeThrough(fn (CacheInterface $c) => $c->delete($key));
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $keys = is_array($keys) ? $keys : iterator_to_array($keys);

        return $this->writeThrough(fn (CacheInterface $c) => $c->deleteMultiple($keys));
    }

    public function clear(): bool
    {
        return $this->writeThrough(fn (CacheInterface $c) => $c->clear());
    }

    public function getExpiredEntryCount(): ?int
    {
        $primaryCount = $this->primary instanceof CacheMaintenanceInterface
            ? $this->primary->getExpiredEntryCount()
            : null;
        $secondaryCount = $this->secondary instanceof CacheMaintenanceInterface
            ? $this->secondary->getExpiredEntryCount()
            : null;

        return $primaryCount === null && $secondaryCount === null
            ? null
            : ($primaryCount ?? 0) + ($secondaryCount ?? 0);
    }

    /** @return array{entries:int,expired:int,storage_bytes:int,items:list<array{fingerprint:string,expires_in:int|null,size_bytes:int}>} */
    public function getProfilerSnapshot(int $limit = 100): array
    {
        $preferred = $this->breaker->isOpen() ? $this->secondary : $this->primary;
        $alternate = $this->breaker->isOpen() ? $this->primary : $this->secondary;

        foreach ([$preferred, $alternate] as $backend) {
            if ($backend instanceof CacheDiagnosticsInterface) {
                return $backend->getProfilerSnapshot($limit);
            }
        }

        throw new \RuntimeException('Neither cache backend supports profiler diagnostics.');
    }

    public function cleanupExpired(): ?int
    {
        $primaryDeleted = $this->primary instanceof CacheMaintenanceInterface
            ? $this->primary->cleanupExpired()
            : null;
        $secondaryDeleted = $this->secondary instanceof CacheMaintenanceInterface
            ? $this->secondary->cleanupExpired()
            : null;

        return $primaryDeleted === null && $secondaryDeleted === null
            ? null
            : ($primaryDeleted ?? 0) + ($secondaryDeleted ?? 0);
    }

    /**
     * Reads prefer the primary; the secondary is only consulted when the
     * primary is unreachable (breaker already open, or it just threw).
     */
    private function readThrough(callable $onPrimary, callable $onSecondary): mixed
    {
        if ($this->breaker->isOpen()) {
            return $onSecondary();
        }

        try {
            $result = $onPrimary($this->primary);
            $this->breaker->reset();

            return $result;
        } catch (RedisException $e) {
            $this->tripAndLog($e);

            return $onSecondary();
        }
    }

    /**
     * Writes always land on the secondary first (durability guarantee
     * independent of Redis's health), then attempt the primary unless
     * the breaker is already open, so Redis stays in sync whenever it's
     * actually reachable.
     */
    private function writeThrough(callable $operation): bool
    {
        $secondaryResult = $operation($this->secondary);

        if ($this->breaker->isOpen()) {
            return $secondaryResult;
        }

        try {
            $primaryResult = $operation($this->primary);
            if (!$primaryResult) {
                $this->tripAndLog(new RedisException('Redis cache write returned false.'));

                return $secondaryResult;
            }

            $this->breaker->reset();

            return $secondaryResult;
        } catch (RedisException $e) {
            $this->tripAndLog($e);

            return $secondaryResult;
        }
    }

    private function tripAndLog(RedisException $e): void
    {
        $this->breaker->trip();
        $this->logger?->warning('Redis unreachable, falling back to filesystem cache', [
            'exception' => $e->getMessage(),
        ]);
    }
}
