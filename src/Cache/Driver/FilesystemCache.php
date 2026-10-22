<?php
declare(strict_types=1);

namespace Zara\Cache\Driver;

use Psr\SimpleCache\CacheInterface;
use Zara\Cache\Interfaces\CacheDiagnosticsInterface;
use Zara\Cache\Interfaces\CacheMaintenanceInterface;
use Zara\Cache\Serializer\CacheSerializerInterface;
use Zara\Cache\Support\TtlNormalizer;

/**
 * Filesystem-backed PSR-16 cache driver. Serves as the on-disk fallback
 * behind FallbackCache when Redis is unreachable, but works standalone
 * too (CacheManager::make() can wire it directly like any other driver).
 *
 * Same convention as the other drivers: no key validation here
 * (ValidatingCache owns that at the decorator level), just storage.
 */
final class FilesystemCache implements CacheInterface, CacheDiagnosticsInterface, CacheMaintenanceInterface
{
    public function __construct(
        private readonly string $directory,
        private readonly CacheSerializerInterface $serializer,
    ) {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(sprintf('Cache directory "%s" could not be created.', $this->directory));
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->pathFor($key);

        if (!is_file($path)) {
            return $default;
        }

        $envelope = @file_get_contents($path);

        if ($envelope === false) {
            return $default;
        }

        [$expiresAt, $payload] = $this->serializer->unserialize($envelope);

        if ($expiresAt !== null && $expiresAt <= time()) {
            @unlink($path);

            return $default;
        }

        return $payload;
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $ttlSeconds = TtlNormalizer::toSeconds($ttl);
        if ($ttlSeconds !== null && $ttlSeconds <= 0) {
            return $this->delete($key);
        }

        $expiresAt = $this->expiresAt($ttlSeconds);
        $envelope = $this->serializer->serialize([$expiresAt, $value]);
        $path = $this->pathFor($key);
        $temporaryPath = @tempnam($this->directory, '.zara-cache-');

        if ($temporaryPath === false) {
            return false;
        }

        try {
            if (@file_put_contents($temporaryPath, $envelope, LOCK_EX) === false) {
                return false;
            }

            return @rename($temporaryPath, $path);
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    public function delete(string $key): bool
    {
        $path = $this->pathFor($key);

        return !is_file($path) || @unlink($path);
    }

    public function clear(): bool
    {
        $ok = true;

        foreach (glob($this->directory . '/*.cache') ?: [] as $file) {
            $ok = @unlink($file) && $ok;
        }

        return $ok;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        $ok = true;

        foreach ($values as $key => $value) {
            $ok = $this->set((string) $key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;

        foreach ($keys as $key) {
            $ok = $this->delete($key) && $ok;
        }

        return $ok;
    }

    public function has(string $key): bool
    {
        $miss = new \stdClass();

        return $this->get($key, $miss) !== $miss;
    }

    public function cleanupExpired(): ?int
    {
        $deleted = 0;
        foreach (glob($this->directory . '/*.cache') ?: [] as $path) {
            $expiresAt = $this->readExpiresAt($path);
            if ($expiresAt !== null && $expiresAt <= time()) {
                if (!unlink($path)) {
                    throw new \RuntimeException(sprintf('Unable to delete expired cache entry "%s".', $path));
                }
                ++$deleted;
            }
        }

        return $deleted;
    }

    public function getExpiredEntryCount(): ?int
    {
        $expired = 0;
        foreach (glob($this->directory . '/*.cache') ?: [] as $path) {
            $expiresAt = $this->readExpiresAt($path);
            if ($expiresAt !== null && $expiresAt <= time()) {
                ++$expired;
            }
        }

        return $expired;
    }

    public function getProfilerSnapshot(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $now = time();
        $entries = 0;
        $expired = 0;
        $storageBytes = 0;
        $items = [];

        foreach (glob($this->directory . '/*.cache') ?: [] as $path) {
            $expiresAt = $this->readExpiresAt($path);
            $size = filesize($path);
            if ($size === false) {
                throw new \RuntimeException(sprintf('Unable to inspect cache entry "%s".', $path));
            }

            ++$entries;
            $storageBytes += $size;
            if ($expiresAt !== null && $expiresAt <= $now) {
                ++$expired;
            }
            if (count($items) < $limit) {
                $items[] = [
                    'fingerprint' => substr(hash('sha256', basename($path, '.cache')), 0, 12),
                    'expires_in' => $expiresAt === null ? null : max(0, $expiresAt - $now),
                    'size_bytes' => $size,
                ];
            }
        }

        return [
            'entries' => $entries,
            'expired' => $expired,
            'storage_bytes' => $storageBytes,
            'items' => $items,
        ];
    }

    private function readExpiresAt(string $path): ?int
    {
        $envelope = file_get_contents($path);
        if ($envelope === false) {
            throw new \RuntimeException(sprintf('Unable to read cache entry "%s".', $path));
        }

        $entry = $this->serializer->unserialize($envelope);
        if (
            !is_array($entry)
            || !array_key_exists(0, $entry)
            || !array_key_exists(1, $entry)
            || ($entry[0] !== null && !is_int($entry[0]))
        ) {
            throw new \RuntimeException(sprintf('Cache entry "%s" has an invalid format.', $path));
        }

        return $entry[0];
    }

    private function expiresAt(?int $ttlSeconds): ?int
    {
        return $ttlSeconds === null ? null : time() + $ttlSeconds;
    }

    private function pathFor(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . '.cache';
    }
}
