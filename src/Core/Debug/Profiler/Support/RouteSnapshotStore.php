<?php

declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Support;

use Psr\SimpleCache\CacheInterface;

/**
 * Persists, per route, the list of classes used on the most recent request
 * to hit it — enabling cross-route comparison in ClassUsagePanel without
 * needing a database or a bespoke storage format. Built on top of the
 * existing PSR-16 Zara\Cache module (Redis/filesystem/fallback, whichever
 * the app has wired) rather than inventing new storage.
 *
 * Deliberately NOT injected as a hard constructor dependency of
 * ClassUsagePanel: not every app has the Cache module configured, and this
 * feature should degrade to "comparison unavailable" rather than breaking
 * the whole Classes panel for apps that don't use Zara\Cache.
 */
final class RouteSnapshotStore
{
    private const INDEX_KEY = 'zdb_route_snapshot_index';
    private const SNAPSHOT_PREFIX = 'zdb_route_snapshot_';
    private const TTL_SECONDS = 86400; // 1 day — snapshots are for active dev comparison, not a permanent log
    private const MAX_ROUTES = 30;     // cap so the cache/index can't grow unbounded across a long dev session

    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public static function currentRouteKey(): string
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        return $method . ' ' . $path;
    }

    /** @param list<string> $classNames */
    public function save(string $routeKey, array $classNames): void
    {
        $this->cache->set(self::SNAPSHOT_PREFIX . md5($routeKey), $classNames, self::TTL_SECONDS);

        $index = $this->cache->get(self::INDEX_KEY, []);
        $index[$routeKey] = time();
        arsort($index); // most recently seen first

        if (count($index) > self::MAX_ROUTES) {
            $index = array_slice($index, 0, self::MAX_ROUTES, true);
        }

        $this->cache->set(self::INDEX_KEY, $index, self::TTL_SECONDS);
    }

    /** @return list<string>|null */
    public function get(string $routeKey): ?array
    {
        return $this->cache->get(self::SNAPSHOT_PREFIX . md5($routeKey));
    }

    /** @return array<string, list<string>> routeKey => class names, most recently seen first */
    public function getAllSnapshots(): array
    {
        $index = $this->cache->get(self::INDEX_KEY, []);
        $snapshots = [];

        foreach (array_keys($index) as $routeKey) {
            $classes = $this->get($routeKey);
            if ($classes !== null) {
                $snapshots[$routeKey] = $classes;
            }
        }

        return $snapshots;
    }
}