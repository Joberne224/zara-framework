<?php
declare(strict_types=1);

namespace Zara\Cache;

use Psr\SimpleCache\CacheInterface;

final class ValidatingCache implements \Psr\SimpleCache\CacheInterface
{
    public function __construct(
        private readonly \Psr\SimpleCache\CacheInterface $inner,
        private readonly CacheKeyValidator $validator,
    ) {
    }

    public function getBackend(): CacheInterface
    {
        return $this->inner;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validator->validate($key);

        return $this->inner->get($key, $default);
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validator->validate($key);

        return $this->inner->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        $this->validator->validate($key);

        return $this->inner->delete($key);
    }

    public function clear(): bool
    {
        return $this->inner->clear();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keys = is_array($keys) ? $keys : iterator_to_array($keys);
        $this->validator->validateAll($keys);

        return $this->inner->getMultiple($keys, $default);
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $values = is_array($values) ? $values : iterator_to_array($values);
        $this->validator->validateAll(array_keys($values));

        return $this->inner->setMultiple($values, $ttl);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $keys = is_array($keys) ? $keys : iterator_to_array($keys);
        $this->validator->validateAll($keys);

        return $this->inner->deleteMultiple($keys);
    }

    public function has(string $key): bool
    {
        $this->validator->validate($key);

        return $this->inner->has($key);
    }
}