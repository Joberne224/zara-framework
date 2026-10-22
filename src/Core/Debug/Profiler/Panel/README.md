# Cache Panel and Cleanup

## Panel wiring

The `CachePanel` displays two data sources:

- `CacheTracker` and `ProfiledCache` record cache operations for the current request (operation, result, duration, hits, and misses).
- The backend is inspected read-only only if it implements `CacheDiagnosticsInterface`. SQLite and filesystem drivers provide bounded snapshots of entries, TTLs, sizes, and expired entries. Redis provides a bounded snapshot of keys in the configured prefix; Redis manages expiration natively, so expired keys are not listed. `FallbackCache` reports diagnostics from its active backend, preferring the filesystem while its Redis circuit breaker is open.

`CachePanel` is registered with the `profiler.panel` tag in the service configuration. `public/index.php` adds tagged panels to the profiler when it is enabled. Expired entries in the snapshot are collapsed in the panel; this display does not purge the cache.

The application console receives the configured PSR-16 cache service from `bin/console`. `Application` registers `CacheCleanupCommand` when this service is provided. Cleanup is capability-based through `CacheMaintenanceInterface`; storage compaction is optional through `CacheCompactionInterface`.

## Commands

Run from the project root:

```sh
php bin/console cache:cleanup
```

This is a dry run: it displays the cache backend and the number of expired entries, when the backend can count them, without making changes.

To delete expired entries:

```sh
php bin/console cache:cleanup --execute
```

To delete expired entries and then compact the database:

```sh
php bin/console cache:cleanup --execute --vacuum
```

`--vacuum` requires `--execute` and is available only for backends that support compaction (currently SQLite). The command is restricted to environments where Zara considers the profiler enabled, meaning development with debug mode enabled.

Backend behavior:

- SQLite and filesystem backends count and delete expired entries.
- Redis expires keys natively; the command reports that no manual purge is needed.
- Other cache drivers must implement `CacheMaintenanceInterface` to support cleanup. Unsupported backends produce an explicit error.

## Precautions

- Start with the dry run, then explicitly pass `--execute` to purge entries.
- SQLite `VACUUM` rebuilds the database file and may take time or temporarily lock the database; use it when a brief interruption is acceptable.
- In some configurations, the SQLite cache file and application database may be the same file. In that case, `VACUUM` compacts the entire database, but cleanup deletes only expired rows from the cache table.
- The commands are not exposed over HTTP. The profiler snapshot remains read-only.
