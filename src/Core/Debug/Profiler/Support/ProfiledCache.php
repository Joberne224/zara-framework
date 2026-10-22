<?php
declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Support;

use DateInterval;
use Psr\SimpleCache\CacheInterface;
use Throwable;
use Zara\Core\Config\Env;

/** Adds development-only measurements around the application's cache service. */
final class ProfiledCache implements CacheInterface
{
    public function __construct(private readonly CacheInterface $inner)
    {
        CacheTracker::current()->setDriver($this->backendName($inner));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!Env::isProfilerEnabled()) {
            return $this->inner->get($key, $default);
        }

        $miss = new \stdClass();
        $startedAt = hrtime(true);

        try {
            $value = $this->inner->get($key, $miss);
            $hit = $value !== $miss;
            $this->record('get', $hit ? 'Hit' : 'Miss', $startedAt, (int) $hit, (int) !$hit);

            return $hit ? $value : $default;
        } catch (Throwable $exception) {
            $this->record('get', 'Error', $startedAt);
            throw $exception;
        }
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        if (!Env::isProfilerEnabled()) {
            return $this->inner->set($key, $value, $ttl);
        }

        return $this->measure('set', fn(): bool => $this->inner->set($key, $value, $ttl));
    }

    public function delete(string $key): bool
    {
        if (!Env::isProfilerEnabled()) {
            return $this->inner->delete($key);
        }

        return $this->measure('delete', fn(): bool => $this->inner->delete($key));
    }

    public function clear(): bool
    {
        if (!Env::isProfilerEnabled()) {
            return $this->inner->clear();
        }

        return $this->measure('clear', fn(): bool => $this->inner->clear());
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        if (!Env::isProfilerEnabled()) {
            return $this->inner->getMultiple($keys, $default);
        }

        $startedAt = hrtime(true);
        $miss = new \stdClass();

        try {
            $keys = array_values(is_array($keys) ? $keys : iterator_to_array($keys));
            $values = $this->inner->getMultiple($keys, $miss);
            $valuesByKey = [];
            foreach ($values as $key => $value) {
                $valuesByKey[$key] = $value;
            }

            $result = [];
            $hits = 0;
            $misses = 0;
            foreach ($keys as $key) {
                $value = array_key_exists($key, $valuesByKey) ? $valuesByKey[$key] : $miss;
                if ($value === $miss) {
                    ++$misses;
                    $result[$key] = $default;
                } else {
                    ++$hits;
                    $result[$key] = $value;
                }
            }

            $this->record('getMultiple', 'OK', $startedAt, $hits, $misses, count($keys));

            return $result;
        } catch (Throwable $exception) {
            $this->record('getMultiple', 'Error', $startedAt);
            throw $exception;
        }
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        if (!Env::isProfilerEnabled()) {
            return $this->inner->setMultiple($values, $ttl);
        }

        $startedAt = hrtime(true);
        try {
            $values = is_array($values) ? $values : iterator_to_array($values);
            $result = $this->inner->setMultiple($values, $ttl);
            $this->record('setMultiple', $result ? 'OK' : 'Failed', $startedAt, items: count($values));

            return $result;
        } catch (Throwable $exception) {
            $this->record('setMultiple', 'Error', $startedAt);
            throw $exception;
        }
    }

    public function deleteMultiple(iterable $keys): bool
    {
        if (!Env::isProfilerEnabled()) {
            return $this->inner->deleteMultiple($keys);
        }

        $startedAt = hrtime(true);
        try {
            $keys = is_array($keys) ? $keys : iterator_to_array($keys);
            $result = $this->inner->deleteMultiple($keys);
            $this->record('deleteMultiple', $result ? 'OK' : 'Failed', $startedAt, items: count($keys));

            return $result;
        } catch (Throwable $exception) {
            $this->record('deleteMultiple', 'Error', $startedAt);
            throw $exception;
        }
    }

    public function has(string $key): bool
    {
        if (!Env::isProfilerEnabled()) {
            return $this->inner->has($key);
        }

        $startedAt = hrtime(true);
        try {
            $exists = $this->inner->has($key);
            $this->record('has', $exists ? 'Hit' : 'Miss', $startedAt, (int) $exists, (int) !$exists);

            return $exists;
        } catch (Throwable $exception) {
            $this->record('has', 'Error', $startedAt);
            throw $exception;
        }
    }

    public function getBackend(): CacheInterface
    {
        return $this->inner;
    }

    private function measure(string $operation, callable $callback): bool
    {
        $startedAt = hrtime(true);
        try {
            $result = $callback();
            $this->record($operation, $result ? 'OK' : 'Failed', $startedAt);

            return $result;
        } catch (Throwable $exception) {
            $this->record($operation, 'Error', $startedAt);
            throw $exception;
        }
    }

    private function record(
        string $operation,
        string $status,
        int $startedAt,
        int $hits = 0,
        int $misses = 0,
        int $items = 1,
    ): void {
        CacheTracker::current()->record(
            $operation,
            $status,
            (hrtime(true) - $startedAt) / 1_000_000,
            $hits,
            $misses,
            $items,
        );
    }

    private function backendName(CacheInterface $cache): string
    {
        $class = (new \ReflectionClass($cache))->getShortName();
        return $class !== '' ? $class : get_debug_type($cache);
    }
}
