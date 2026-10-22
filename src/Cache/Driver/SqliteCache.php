<?php
declare(strict_types=1);

namespace Zara\Cache\Driver;

use Zara\Cache\CacheKeyValidator;
use Zara\Cache\Interfaces\CacheCompactionInterface;
use Zara\Cache\Interfaces\CacheDiagnosticsInterface;
use Zara\Cache\Interfaces\CacheMaintenanceInterface;
use Zara\Cache\Exception\CacheException;
use Zara\Cache\Serializer\CacheSerializerInterface;
use Zara\Cache\SqliteCacheConfig;
use Zara\Cache\Support\TtlNormalizer;

/**
 * PSR-16 cache backed by a PRIVATE, dedicated SQLite connection — not
 * Zara\Database\SqliteDatabase. Two reasons to keep the two separate:
 *
 *  - Sharing SqliteDatabase would route every cache get()/set()/has() call
 *    through AbstractSqlDatabase::executeTracked(), the single hook point
 *    the debugbar's QueryPanel instruments. A cache hit 50 times in one
 *    request would then show up as 50 "queries" in the profiler, noise
 *    indistinguishable from genuine N+1s in getRepeatedQueries() — no
 *    other PSR-16 driver (RedisCache, FilesystemCache) goes through an
 *    instrumented layer like this, and this one shouldn't either.
 *  - A connection owned entirely by this class, with no other code able to
 *    reach it, structurally cannot suffer the "another consumer called
 *    disconnect()/connect() on the shared connection and silently
 *    invalidated my PRAGMA settings" problem — there is no other consumer.
 *    No generation counter, no staleness detection needed: the PDO handle
 *    lives and dies with this object.
 *
 * Schema is fixed and known in advance (key/value/expires_at), never
 * parameterized by caller input, so this talks to PDO directly rather than
 * through QueryBuilder — that generality (arbitrary tables/columns, 3
 * swappable engines) buys nothing here and would only add a layer to
 * traverse for SQL this class already writes by hand regardless.
 */
final class SqliteCache implements
    \Psr\SimpleCache\CacheInterface,
    CacheDiagnosticsInterface,
    CacheMaintenanceInterface,
    CacheCompactionInterface
{
    /**
     * Kept comfortably under SQLite's own bound-parameter ceiling
     * (SQLITE_MAX_VARIABLE_NUMBER: 999 on older builds, 32766 on recent
     * ones) for the IN (...) lists getMultiple()/deleteMultiple() build.
     * Unrelated to ConditionCompiler::MAX_IN_LIST_SIZE elsewhere in Zara —
     * this driver never goes through QueryBuilder, so that limit doesn't
     * apply here; this one exists for the same underlying reason, enforced
     * independently because the code path is independent.
     */
    private const CHUNK_SIZE = 500;

    private ?\PDO $pdo = null;
    private readonly CacheKeyValidator $keyValidator;

    public function __construct(
        private readonly SqliteCacheConfig $config,
        private readonly CacheSerializerInterface $serializer,
    ) {
        $this->keyValidator = new CacheKeyValidator();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->keyValidator->validate($key);

        try {
            $stmt = $this->pdo()->prepare('SELECT value, expires_at FROM ' . $this->quotedTable() . ' WHERE "key" = ?');
            $stmt->execute([$key]);
            $row = $stmt->fetch();

            if ($row === false) {
                return $default;
            }

            if ($this->isExpired($row['expires_at'])) {
                $this->delete($key);
                return $default;
            }

            return $this->serializer->unserialize((string) $row['value']);
        } catch (\PDOException $exception) {
            throw $this->readFailure($exception);
        }
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->keyValidator->validate($key);
        $seconds = TtlNormalizer::toSeconds($ttl);

        if ($seconds !== null && $seconds <= 0) {
            return $this->delete($key);
        }

        $expiresAt = $seconds !== null ? time() + $seconds : null;
        $payload = $this->serializer->serialize($value);

        try {
            $sql = 'INSERT INTO ' . $this->quotedTable() . ' ("key", "value", expires_at)
                    VALUES (?, ?, ?)
                    ON CONFLICT("key") DO UPDATE SET
                        "value" = excluded."value",
                        expires_at = excluded.expires_at';

            $this->pdo()->prepare($sql)->execute([$key, $payload, $expiresAt]);
            $this->collectGarbageIfNeeded();

            return true;
        } catch (\PDOException) {
            // Storage failures are reported through the PSR-16 boolean result.
            return false;
        }
    }

    public function delete(string $key): bool
    {
        $this->keyValidator->validate($key);

        try {
            $this->pdo()->prepare('DELETE FROM ' . $this->quotedTable() . ' WHERE "key" = ?')->execute([$key]);
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    public function clear(): bool
    {
        try {
            $this->pdo()->exec('DELETE FROM ' . $this->quotedTable());
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keys = is_array($keys) ? $keys : iterator_to_array($keys);
        $this->keyValidator->validateAll($keys);

        if ($keys === []) {
            return [];
        }

        $found = [];
        $expiredKeys = [];

        try {
            foreach (array_chunk($keys, self::CHUNK_SIZE) as $chunk) {
                $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
                $stmt = $this->pdo()->prepare(
                    'SELECT "key", "value", expires_at FROM ' . $this->quotedTable()
                    . " WHERE \"key\" IN ({$placeholders})"
                );
                $stmt->execute(array_values($chunk));

                foreach ($stmt->fetchAll() as $row) {
                    $key = (string) $row['key'];

                    if ($this->isExpired($row['expires_at'])) {
                        $expiredKeys[] = $key;
                        continue;
                    }

                    $found[$key] = $this->serializer->unserialize((string) $row['value']);
                }
            }
        } catch (\PDOException $exception) {
            throw $this->readFailure($exception);
        }

        if ($expiredKeys !== []) {
            $this->deleteMultiple($expiredKeys);
        }

        $result = [];
        foreach ($keys as $key) {
            $result[$key] = array_key_exists($key, $found) ? $found[$key] : $default;
        }

        return $result;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $values = is_array($values) ? $values : iterator_to_array($values);
        $this->keyValidator->validateAll(array_keys($values));

        if ($values === []) {
            return true;
        }

        try {
            $pdo = $this->pdo();
            $pdo->beginTransaction();

            foreach ($values as $key => $value) {
                if (!$this->set($key, $value, $ttl)) {
                    $pdo->rollBack();
                    return false;
                }
            }

            $pdo->commit();
            return true;
        } catch (\PDOException) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return false;
        } catch (\Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $keys = is_array($keys) ? $keys : iterator_to_array($keys);
        $this->keyValidator->validateAll($keys);

        if ($keys === []) {
            return true;
        }

        try {
            foreach (array_chunk($keys, self::CHUNK_SIZE) as $chunk) {
                $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
                $this->pdo()
                    ->prepare('DELETE FROM ' . $this->quotedTable() . " WHERE \"key\" IN ({$placeholders})")
                    ->execute(array_values($chunk));
            }

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    public function has(string $key): bool
    {
        $this->keyValidator->validate($key);

        try {
            $stmt = $this->pdo()->prepare('SELECT expires_at FROM ' . $this->quotedTable() . ' WHERE "key" = ?');
            $stmt->execute([$key]);
            $row = $stmt->fetch();

            if ($row === false) {
                return false;
            }

            if ($this->isExpired($row['expires_at'])) {
                $this->delete($key);
                return false;
            }

            return true;
        } catch (\PDOException $exception) {
            throw $this->readFailure($exception);
        }
    }

    public function getProfilerSnapshot(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $now = time();

        try {
            $summary = $this->pdo()->query(
                'SELECT COUNT(*) AS entries,
                    SUM(CASE WHEN expires_at IS NOT NULL AND expires_at <= ' . $now . ' THEN 1 ELSE 0 END) AS expired,
                    COALESCE(SUM(length("value")), 0) AS storage_bytes
                 FROM ' . $this->quotedTable()
            )->fetch();

            $statement = $this->pdo()->prepare(
                'SELECT "key", expires_at, length("value") AS size_bytes
                 FROM ' . $this->quotedTable() . '
                 ORDER BY "key" ASC LIMIT ?'
            );
            $statement->bindValue(1, $limit, \PDO::PARAM_INT);
            $statement->execute();

            $items = [];
            foreach ($statement->fetchAll() as $row) {
                $expiresAt = $row['expires_at'] === null ? null : (int) $row['expires_at'];
                $items[] = [
                    'fingerprint' => substr(hash('sha256', (string) $row['key']), 0, 12),
                    'expires_in' => $expiresAt === null ? null : max(0, $expiresAt - $now),
                    'size_bytes' => (int) $row['size_bytes'],
                ];
            }

            return [
                'entries' => (int) ($summary['entries'] ?? 0),
                'expired' => (int) ($summary['expired'] ?? 0),
                'storage_bytes' => (int) ($summary['storage_bytes'] ?? 0),
                'items' => $items,
            ];
        } catch (\PDOException $exception) {
            throw new CacheException('Unable to inspect SQLite cache storage.', 0, $exception);
        }
    }

    public function getExpiredEntryCount(): ?int
    {
        $statement = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM ' . $this->quotedTable() . ' WHERE expires_at IS NOT NULL AND expires_at <= ?'
        );
        $statement->execute([time()]);
        $expired = (int) $statement->fetchColumn();
        $statement->closeCursor();

        return $expired;
    }

    public function cleanupExpired(): ?int
    {
        $statement = $this->pdo()
            ->prepare('DELETE FROM ' . $this->quotedTable() . ' WHERE expires_at IS NOT NULL AND expires_at <= ?');
        $statement->execute([time()]);

        return $statement->rowCount();
    }

    public function compact(): void
    {
        $this->pdo()->exec('VACUUM');
    }

    private function isExpired(mixed $expiresAt): bool
    {
        return $expiresAt !== null && (int) $expiresAt <= time();
    }

    private function quotedTable(): string
    {
        return '"' . $this->config->table . '"';
    }

    private function readFailure(\PDOException $exception): CacheException
    {
        return new CacheException('SQLite cache read failed.', 0, $exception);
    }

    /**
     * Probabilistic garbage collection to purge expired rows without
     * doing it on every single write.
     */
    private function collectGarbageIfNeeded(): void
    {
        if ($this->config->gcProbability <= 0 || mt_rand(1, $this->config->gcProbability) !== 1) {
            return;
        }

        try {
            $this->pdo()
                ->prepare('DELETE FROM ' . $this->quotedTable() . ' WHERE expires_at IS NOT NULL AND expires_at <= ?')
                ->execute([time()]);
        } catch (\PDOException) {
            // Garbage collection failure should never break the caller's set().
        }
    }

    /**
     * Lazily opens the private connection and runs its one-time setup.
     * Safe to call on every public method with no staleness check at all:
     * nothing outside this class can ever disconnect $this->pdo, so once
     * it's non-null, it never needs to be re-examined.
     */
    private function pdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $dsn = $this->config->isInMemory() ? 'sqlite::memory:' : 'sqlite:' . $this->config->path;

        $pdo = new \PDO($dsn, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA synchronous = NORMAL;');
        $pdo->exec('PRAGMA busy_timeout = ' . $this->config->busyTimeoutMs . ';');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . $this->quotedTable() . ' (
                "key" TEXT PRIMARY KEY,
                "value" TEXT NOT NULL,
                expires_at INTEGER NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS "idx_' . $this->config->table . '_expires_at" ON '
            . $this->quotedTable() . '(expires_at)');

        $this->pdo = $pdo;

        return $this->pdo;
    }
}
