<?php
// Zara\Cache\SqliteCacheConfig.php
declare(strict_types=1);

namespace Zara\Cache;

/**
 * Same convention as DatabaseConfig/RedisConfig/HttpClientConfig: scalars
 * and validation only, no service instances. Deliberately NOT SqliteConfig
 * (the Database layer's own config) — this driver owns a private,
 * dedicated PDO connection rather than sharing Zara\Database\SqliteDatabase,
 * so it has no reason to share that config shape either.
 */
final class SqliteCacheConfig
{
    public function __construct(
        public readonly string $path,
        public readonly string $table = 'cache',
        public readonly int $gcProbability = 100,
        public readonly int $busyTimeoutMs = 5000,
    ) {
        if (trim($path) === '') {
            throw new \InvalidArgumentException('SQLite cache path cannot be empty.');
        }

        // Interpolated directly into raw SQL: this driver has no query
        // builder or identifier quoter in front of it (unlike the main
        // Database layer), so the table name is validated once, here,
        // rather than trusted at every query.
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid cache table name: %s. Only alphanumeric characters and underscores are allowed.',
                $table,
            ));
        }

        if (str_starts_with(strtolower($table), 'sqlite_')) {
            throw new \InvalidArgumentException('SQLite cache table names cannot use the reserved "sqlite_" prefix.');
        }

        if ($gcProbability < 0) {
            throw new \InvalidArgumentException('gcProbability cannot be negative.');
        }

        if ($busyTimeoutMs < 0) {
            throw new \InvalidArgumentException('busyTimeoutMs cannot be negative.');
        }
    }

    public function isInMemory(): bool
    {
        return $this->path === ':memory:';
    }
}
