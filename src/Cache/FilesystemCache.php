<?php

declare(strict_types=1);

namespace Zara\Cache;

use Psr\SimpleCache\CacheInterface;
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
final class FilesystemCache implements CacheInterface
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
        $expiresAt = $this->expiresAt(TtlNormalizer::toSeconds($ttl));
        $envelope = $this->serializer->serialize([$expiresAt, $value]);

        return @file_put_contents($this->pathFor($key), $envelope, LOCK_EX) !== false;
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

    private function expiresAt(?int $ttlSeconds): ?int
    {
        return $ttlSeconds === null ? null : time() + $ttlSeconds;
    }

    private function pathFor(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . '.cache';
    }
}
