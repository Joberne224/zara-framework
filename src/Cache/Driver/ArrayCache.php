<?php
// Zara\Cache\Driver\ArrayCache.php
declare(strict_types=1);

namespace Zara\Cache\Driver;

use Zara\Cache\Support\TtlNormalizer;

final class ArrayCache implements \Psr\SimpleCache\CacheInterface
{
    /** @var array<string, array{value: mixed, expiresAt: int|null}> */
    private array $store = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->has($key) ? $this->store[$key]['value'] : $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $seconds = TtlNormalizer::toSeconds($ttl);
        $this->store[$key] = [
            'value' => $value,
            'expiresAt' => $seconds === null ? null : time() + $seconds,
        ];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->store = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        if (!array_key_exists($key, $this->store)) {
            return false;
        }

        $expiresAt = $this->store[$key]['expiresAt'];
        if ($expiresAt !== null && $expiresAt <= time()) {
            unset($this->store[$key]);

            return false;
        }

        return true;
    }
}