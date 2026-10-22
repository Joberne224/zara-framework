<?php
declare(strict_types=1);

namespace Zara\Cache\Driver;

use Zara\Cache\Exception\ConnectionException;
use Zara\Cache\Interfaces\CacheDiagnosticsInterface;
use Zara\Cache\Interfaces\CacheMaintenanceInterface;
use Zara\Cache\RedisConfig;
use Zara\Cache\Serializer\CacheSerializerInterface;
use Zara\Cache\Support\TtlNormalizer;

final class RedisCache implements
    \Psr\SimpleCache\CacheInterface,
    CacheDiagnosticsInterface,
    CacheMaintenanceInterface
{
    private readonly \Redis $client;
    private readonly string $keyPrefix;

    public function __construct(
        RedisConfig $config,
        private readonly CacheSerializerInterface $serializer,
    ) {
        if (!extension_loaded('redis')) {
            throw new ConnectionException(
                "The 'redis' PHP extension is not loaded. Install it via PECL " .
                "(pecl install redis) or your OS package manager, then enable it in php.ini."
            );
        }

        $client = new \Redis();

        try {
            if (!$client->connect($config->host, $config->port, $config->timeout)) {
                throw new \RedisException('Redis connection was not established.');
            }

            // Make command-level Redis errors observable to FallbackCache.
            if (defined('Redis::OPT_THROW_ON_ERROR')) {
                $client->setOption(\Redis::OPT_THROW_ON_ERROR, true);
            }
            if (defined('Redis::OPT_SCAN') && defined('Redis::SCAN_RETRY')) {
                $client->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
            }

            if ($config->password !== null) {
                if (!$client->auth($config->password)) {
                    throw new \RedisException('Redis authentication failed.');
                }
            }

            if (!$client->select($config->database)) {
                throw new \RedisException('Redis database selection failed.');
            }
        } catch (\RedisException $e) {
            throw new ConnectionException(
                "Unable to connect to Redis at {$config->host}:{$config->port}.",
                previous: $e,
            );
        }

        $this->client = $client;
        $this->keyPrefix = $config->keyPrefix;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $raw = $this->client->get($this->redisKey($key));

        return $raw === false ? $default : $this->serializer->unserialize($raw);
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $seconds = TtlNormalizer::toSeconds($ttl);
        if ($seconds !== null && $seconds <= 0) {
            return $this->delete($key);
        }

        $payload = $this->serializer->serialize($value);
        $redisKey = $this->redisKey($key);

        return $seconds === null
            ? $this->client->set($redisKey, $payload)
            : $this->client->setex($redisKey, $seconds, $payload);
    }

    public function delete(string $key): bool
    {
        return $this->client->del($this->redisKey($key)) !== false;
    }

    public function clear(): bool
    {
        // Only remove this cache's own entries; the selected Redis database
        // may also contain sessions or data owned by other applications.
        $iterator = null;
        $keysToDelete = [];

        do {
            $keys = $this->client->scan($iterator, $this->keyPrefix . '*', 500);
            if ($keys === false) {
                break;
            }

            if ($keys !== []) {
                array_push($keysToDelete, ...$keys);
            }
        } while ($iterator > 0);

        // Delete after the scan is complete so shrinking Redis keyspace
        // tables cannot make the cursor skip keys that still need clearing.
        foreach (array_chunk($keysToDelete, 500) as $chunk) {
            if ($this->client->del($chunk) === false) {
                return false;
            }
        }

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keys = array_values(is_array($keys) ? $keys : iterator_to_array($keys));
        if ($keys === []) {
            return [];
        }

        $redisKeys = array_map(fn (string $key): string => $this->redisKey($key), $keys);
        $values = $this->client->mget($redisKeys);

        $result = [];
        foreach ($keys as $i => $key) {
            $raw = $values[$i];
            $result[$key] = $raw === false ? $default : $this->serializer->unserialize($raw);
        }

        return $result;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        // Native MSET does not support a per-key TTL: setex one by one.
        // Redis pipelining possible later if volume justifies it.
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->set((string) $key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $keys = is_array($keys) ? $keys : iterator_to_array($keys);

        $redisKeys = array_map(fn (string $key): string => $this->redisKey($key), $keys);

        return $redisKeys === [] || $this->client->del($redisKeys) !== false;
    }

    public function has(string $key): bool
    {
        return $this->client->exists($this->redisKey($key)) > 0;
    }

    public function getProfilerSnapshot(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $iterator = null;
        $entries = 0;
        $storageBytes = 0;
        $items = [];

        do {
            $keys = $this->client->scan($iterator, $this->keyPrefix . '*', 500);
            if ($keys === false) {
                break;
            }

            foreach ($keys as $redisKey) {
                if (!str_starts_with((string) $redisKey, $this->keyPrefix)) {
                    continue;
                }

                $ttl = $this->client->ttl($redisKey);
                if ($ttl === -2) {
                    continue;
                }

                ++$entries;
                $size = (int) $this->client->strlen($redisKey);
                $storageBytes += $size;
                if (count($items) >= $limit) {
                    continue;
                }

                $items[] = [
                    'fingerprint' => substr(hash('sha256', (string) $redisKey), 0, 12),
                    'expires_in' => $ttl < 0 ? null : $ttl,
                    'size_bytes' => $size,
                ];
            }
        } while ($iterator > 0);

        return [
            'entries' => $entries,
            'expired' => 0,
            'storage_bytes' => $storageBytes,
            'items' => $items,
        ];
    }

    public function cleanupExpired(): ?int
    {
        return null;
    }

    public function getExpiredEntryCount(): ?int
    {
        return null;
    }

    private function redisKey(string $key): string
    {
        return $this->keyPrefix . $key;
    }
}
