<?php
// Zara\Cache\CacheManager.php
declare(strict_types=1);

namespace Zara\Cache;

use Psr\Log\LoggerInterface;
use Zara\Cache\Driver\ArrayCache;
use Zara\Cache\Driver\FallbackCache;
use Zara\Cache\Driver\FilesystemCache;
use Zara\Cache\Driver\NullCache;
use Zara\Cache\Driver\RedisCache;
use Zara\Cache\Exception\ConnectionException;
use Zara\Cache\Serializer\CacheSerializerInterface;
use Zara\Cache\Serializer\PhpSerializer;
use Zara\Cache\Support\CircuitBreakerFile;

final class CacheManager
{
    /**
     * @param string|null $fallbackDirectory Required when $driver is CacheDriver::Fallback:
     *   the disk directory FilesystemCache writes to. Must live outside the webroot.
     * @param string|null $filesystemDirectory Required when $driver is CacheDriver::Filesystem.
     * @param SqliteCacheConfig|null $sqliteConfig Required when $driver is CacheDriver::Sqlite.
     * @param string|null $fallbackBreakerMarkerPath Where the circuit breaker's marker file
     *   lives. Defaults to a hidden file inside $fallbackDirectory.
     * @param int $fallbackBreakerCooldownSeconds How long the breaker stays open (skipping
     *   Redis entirely) after a failure, before the next request is allowed to retry it.
     */
    public static function make(
        CacheDriver $driver,
        ?RedisConfig $redisConfig = null,
        ?CacheSerializerInterface $serializer = null,
        ?string $fallbackDirectory = null,
        ?string $fallbackBreakerMarkerPath = null,
        int $fallbackBreakerCooldownSeconds = 30,
        ?LoggerInterface $logger = null,
        ?string $filesystemDirectory = null,
        ?SqliteCacheConfig $sqliteConfig = null,
    ): \Psr\SimpleCache\CacheInterface {
        $serializer ??= new PhpSerializer();

        $store = match ($driver) {
            CacheDriver::Redis => new RedisCache(
                $redisConfig ?? throw new \InvalidArgumentException('RedisConfig is required for the Redis cache driver.'),
                $serializer,
            ),
            CacheDriver::Filesystem => new FilesystemCache(
                $filesystemDirectory ?? throw new \InvalidArgumentException(
                    'filesystemDirectory is required for the filesystem cache driver.'
                ),
                $serializer,
            ),
            CacheDriver::Sqlite => new \Zara\Cache\Driver\SqliteCache(
                $sqliteConfig ?? throw new \InvalidArgumentException(
                    'SqliteCacheConfig is required for the SQLite cache driver.'
                ),
                $serializer,
            ),
            CacheDriver::Array => new ArrayCache(),
            CacheDriver::Null => new NullCache(),
            CacheDriver::Fallback => self::makeFallback(
                $redisConfig ?? throw new \InvalidArgumentException('RedisConfig is required for the fallback cache driver.'),
                $serializer,
                $fallbackDirectory ?? throw new \InvalidArgumentException('fallbackDirectory is required for the fallback cache driver.'),
                $fallbackBreakerMarkerPath ?? rtrim($fallbackDirectory, '/') . '/.redis-circuit-breaker',
                $fallbackBreakerCooldownSeconds,
                $logger,
            ),
        };

        return new ValidatingCache($store, new CacheKeyValidator());
    }

    /**
     * Builds the Redis+filesystem fallback stack.
     *
     * RedisCache connects eagerly in its constructor (see RedisCache::__construct),
     * so a Redis outage throws ConnectionException right here — before a
     * FallbackCache instance even exists to catch anything from inside get()/set().
     * We catch it at this single point, trip the breaker immediately, and stand
     * in a NullCache as the "primary": since the breaker is already open,
     * FallbackCache's own short-circuit means that NullCache is never actually
     * invoked — every read/write goes straight to the filesystem cache until the
     * breaker's cooldown elapses and a fresh CacheManager::make() call (e.g. the
     * next request) gets a real, reconnected RedisCache again.
     */
    private static function makeFallback(
        RedisConfig $redisConfig,
        CacheSerializerInterface $serializer,
        string $fallbackDirectory,
        string $breakerMarkerPath,
        int $breakerCooldownSeconds,
        ?LoggerInterface $logger,
    ): \Psr\SimpleCache\CacheInterface {
        $breaker = new CircuitBreakerFile($breakerMarkerPath, $breakerCooldownSeconds);
        $secondary = new FilesystemCache($fallbackDirectory, $serializer);

        try {
            $primary = new RedisCache($redisConfig, $serializer);
        } catch (ConnectionException $e) {
            $breaker->trip();
            $logger?->warning(
                'Redis unreachable while building the cache stack; starting with the circuit breaker open.',
                ['exception' => $e->getMessage()],
            );
            $primary = new NullCache();
        }

        return new FallbackCache(
            $primary,
            $secondary,
            $breaker,
            $logger,
        );
    }
}
