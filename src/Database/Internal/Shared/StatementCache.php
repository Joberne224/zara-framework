<?php

declare(strict_types=1);

namespace Zara\Database\Internal\Shared;

/**
 * Bounded, LRU-ish cache of prepared statements keyed by query string. Bounded so
 * long-running processes (queue workers, etc.) don't grow this cache without limit
 * when many distinct query strings are seen over time.
 *
 * Entries also record the PDO instance they were prepared on, and a cache hit is
 * only served for that same instance: a statement can never be handed back for a
 * different (e.g. reconnected) connection, no matter who forgot to clear() what.
 */
final class StatementCache
{
    /** @var array<string, array{\PDO, \PDOStatement}> insertion order = recency of use */
    private array $statements = [];

    public function __construct(private readonly int $maxSize = 100)
    {
    }

    /**
     * @throws \PDOException If preparation fails. Deliberately NOT wrapped in a
     *   QueryException here: with native (non-emulated) prepares, prepare() is a
     *   server round-trip, so a dropped connection surfaces at this point — and the
     *   StatementExecutors' reconnect logic can only recognise it as a raw
     *   PDOException. AbstractSqlDatabase already translates every PDOException
     *   into the right QueryException at the facade boundary.
     */
    public function prepare(\PDO $pdo, string $query): \PDOStatement
    {
        $entry = $this->statements[$query] ?? null;

        if ($entry !== null && $entry[0] === $pdo) {
            // Re-insert at the end so insertion order tracks recency of use.
            unset($this->statements[$query]);
            $this->statements[$query] = $entry;
            return $entry[1];
        }

        if (count($this->statements) >= $this->maxSize) {
            array_shift($this->statements);
        }

        $stmt = $pdo->prepare($query);
        $this->statements[$query] = [$pdo, $stmt];

        return $stmt;
    }

    public function clear(): void
    {
        $this->statements = [];
    }
}
