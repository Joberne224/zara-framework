<?php
declare(strict_types=1);

namespace Zara\Database;

use Psr\Log\LoggerInterface;
use Zara\Database\Internal\ConnectionInterface;
use Zara\Database\Instrumentation\QueryObserverRegistry;
use Zara\Database\Exceptions\QueryException;
use Zara\Database\Internal\IdentifierQuoterInterface;
use Zara\Database\Internal\PdoProviderInterface;
use Zara\Database\Internal\StatementExecutorInterface;
use Zara\Database\Internal\Shared\StatementCache;
use Zara\Database\Internal\Shared\TransactionManager;

/**
 * Shared DatabaseInterface facade body for every SQL/PDO-backed driver.
 *
 * Extracted verbatim from MysqlDatabase: none of this method logic was ever
 * MySQL-specific — every engine difference already lived in the composed
 * collaborators (connection DSN/lifecycle, identifier quoting, reconnect
 * behavior). A concrete subclass (MysqlDatabase, SqliteDatabase,
 * PostgresDatabase, or a future PostgresDatabase-like driver) only needs to
 * build its own engine-specific connection manager (implementing
 * Internal\ConnectionInterface + PdoProviderInterface)/IdentifierQuoterInterface/
 * StatementExecutorInterface implementations in its constructor and hand
 * them to parent::__construct(); StatementCache and TransactionManager are
 * already engine-agnostic and shared as-is.
 */
abstract class AbstractSqlDatabase implements DatabaseInterface
{
    public function __construct(
        protected readonly ConnectionInterface&PdoProviderInterface $connections,
        protected readonly StatementCache $statements,
        protected readonly TransactionManager $transactions,
        protected readonly StatementExecutorInterface $executor,
        protected readonly IdentifierQuoterInterface $identifiers,
        protected readonly LoggerInterface $logger,
        protected readonly bool $logQueries = true,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function connect(): void
    {
        $this->connections->connect();
    }

    /**
     * {@inheritdoc}
     */
    public function isConnected(): bool
    {
        return $this->connections->isConnected();
    }

    /**
     * Returns safe, non-invasive connection metadata for diagnostics.
     * Does not connect or issue a health-check query.
     *
     * @return array{driver:string,connected:bool,host:?string,port:?int,database:?string,charset:?string,transaction_depth:int}
     */
    public function getProfilerInfo(): array
    {
        return $this->connections->getProfilerInfo() + ['transaction_depth' => $this->transactions->depth()];
    }

    /**
     * {@inheritdoc}
     */
    public function query(string $query, array $params = []): array
    {
        try {
            $this->logger->debug('Executing query: ' . $this->queryForLog($query), ['param_count' => count($params)]);
            $stmt = $this->executeTracked($query, $params, 'query');
            $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $this->logger->debug('Query returned ' . count($result) . ' rows');
            return $result;
        } catch (\PDOException $e) {
            $this->logger->error('Query execution failed: ' . $e->getMessage(), ['query' => $this->queryForLog($query)]);
            throw new QueryException('Query execution failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function execute(string $query, array $params = []): int
    {
        try {
            $this->logger->debug('Executing statement: ' . $this->queryForLog($query), ['param_count' => count($params)]);
            $stmt = $this->executeTracked($query, $params, 'execute');
            $rowCount = $stmt->rowCount();
            $this->logger->debug('Statement affected ' . $rowCount . ' rows');
            return $rowCount;
        } catch (\PDOException $e) {
            $this->logger->error('Statement execution failed: ' . $e->getMessage(), ['query' => $this->queryForLog($query)]);
            throw new QueryException('Statement execution failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function insert(string $table, array $data): int|string
    {
        if ($data === []) {
            throw new \InvalidArgumentException('Cannot insert with an empty data array');
        }

        $quotedTable = $this->identifiers->quote($table);

        $columns = [];
        foreach ($data as $column => $value) {
            if (!is_string($column)) {
                throw new \InvalidArgumentException(sprintf(
                    'Insert data must map column names to values; got an integer key (%d).',
                    $column
                ));
            }
            $columns[] = $this->identifiers->quote($column);
        }

        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $query = "INSERT INTO {$quotedTable} (" . implode(', ', $columns) . ") VALUES ({$placeholders})";

        try {
            $this->logger->debug('Inserting into table: ' . $table, ['columns' => array_keys($data)]);
            $this->executeTracked($query, array_values($data), 'insert');

            // The id is fetched *after* execute() on purpose, through a hook
            // that reconnects lazily: if the executor had to silently
            // reconnect mid-call, the connection the INSERT actually ran on
            // is whatever the connection manager holds now — a PDO instance
            // captured before execute() would reference the discarded, stale
            // connection and lastInsertId() would silently return the wrong
            // value.
            return $this->normalizeInsertId($this->fetchLastInsertId());
        } catch (\PDOException $e) {
            $this->logger->error('Insert failed: ' . $e->getMessage(), ['table' => $table, 'columns' => array_keys($data)]);
            throw new QueryException('Insert failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if ($data === []) {
            throw new \InvalidArgumentException('Cannot update with an empty data array');
        }

        $quotedTable = $this->identifiers->quote($table);

        $setClauses = [];
        foreach ($data as $column => $value) {
            if (!is_string($column)) {
                throw new \InvalidArgumentException(sprintf(
                    'Update data must map column names to values; got an integer key (%d).',
                    $column
                ));
            }
            $setClauses[] = $this->identifiers->quote($column) . ' = ?';
        }

        $query = "UPDATE {$quotedTable} SET " . implode(', ', $setClauses) . " WHERE {$where}";
        $params = [...array_values($data), ...$whereParams];

        try {
            $this->logger->debug('Updating table: ' . $table, ['columns' => array_keys($data)]);
            $stmt = $this->executeTracked($query, $params, 'update');
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            $this->logger->error('Update failed: ' . $e->getMessage(), ['table' => $table, 'columns' => array_keys($data)]);
            throw new QueryException('Update failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $table, string $where, array $whereParams = []): int
    {
        $quotedTable = $this->identifiers->quote($table);
        $query = "DELETE FROM {$quotedTable} WHERE {$where}";

        try {
            $this->logger->debug('Deleting from table: ' . $table);
            $stmt = $this->executeTracked($query, $whereParams, 'delete');
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            $this->logger->error('Delete failed: ' . $e->getMessage(), ['table' => $table]);
            throw new QueryException('Delete failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function fetchOne(string $query, array $params = []): ?array
    {
        try {
            $this->logger->debug('Fetching one row: ' . $this->queryForLog($query), ['param_count' => count($params)]);
            $stmt = $this->executeTracked($query, $params, 'fetchOne');
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            return $result !== false ? $result : null;
        } catch (\PDOException $e) {
            $this->logger->error('Fetch one failed: ' . $e->getMessage(), ['query' => $this->queryForLog($query)]);
            throw new QueryException('Fetch one failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function fetchColumn(string $query, array $params = [], int $column = 0): mixed
    {
        try {
            $this->logger->debug('Fetching column: ' . $this->queryForLog($query), ['param_count' => count($params)]);
            $stmt = $this->executeTracked($query, $params, 'fetchColumn');
            $result = $stmt->fetchColumn($column);
            // A fetch that stops before the end of the result set leaves an open
            // cursor on the statement — and since StatementCache keeps that
            // statement alive, the cursor stays open for the lifetime of the
            // process. On SQLite an open read cursor blocks DDL on the same
            // connection ("database table is locked"), so every partial fetch
            // must reset its statement. On MySQL/Postgres this is a harmless
            // no-op once the driver has buffered or exhausted the rows.
            $stmt->closeCursor();
            return $result !== false ? $result : null;
        } catch (\PDOException $e) {
            $this->logger->error('Fetch column failed: ' . $e->getMessage(), ['query' => $this->queryForLog($query)]);
            throw new QueryException('Fetch column failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function beginTransaction(): void
    {
        $this->connections->connect();
        $this->transactions->begin($this->connections->getPdo());
    }

    /**
     * {@inheritdoc}
     */
    public function commit(): void
    {
        $this->connections->connect();
        $this->transactions->commit($this->connections->getPdo());
    }

    /**
     * {@inheritdoc}
     */
    public function rollback(): void
    {
        $this->connections->connect();
        $this->transactions->rollback($this->connections->getPdo());
    }

    /**
     * {@inheritdoc}
     */
    public function lastInsertId(?string $sequenceName = null): int|string
    {
        try {
            return $this->normalizeInsertId($this->fetchLastInsertId($sequenceName));
        } catch (\PDOException $e) {
            $this->logger->error('lastInsertId failed: ' . $e->getMessage());
            throw new QueryException('lastInsertId failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Driver hook: the raw last-insert-id of the connection the most recent
     * statement ran on, as the string PDO reports it. connect() happens here
     * (lazily, after any execute()) so the id is read from whatever connection
     * the manager currently holds, never from a stale captured PDO instance.
     *
     * Overridden by PostgresDatabase: PDO_PGSQL's lastInsertId() without an
     * explicit sequence name reports the insert OID (0 on modern Postgres)
     * instead of the generated id.
     */
    protected function fetchLastInsertId(?string $sequenceName = null): string
    {
        $this->connections->connect();

        return (string) $this->connections->getPdo()->lastInsertId($sequenceName);
    }

    /**
     * Normalize a raw PDO lastInsertId() string into an int when it is a
     * plain decimal integer that safely fits (the common case), keeping it as
     * a string otherwise. ctype_digit() rather than is_numeric() so a
     * decimal-looking value ("1.5") is passed through untouched instead of
     * being silently truncated by the (int) cast. Shared by insert() and
     * lastInsertId() so the conversion rule only lives once.
     */
    private function normalizeInsertId(string $lastInsertId): int|string
    {
        return ctype_digit($lastInsertId) && $lastInsertId <= PHP_INT_MAX
            ? (int) $lastInsertId
            : $lastInsertId;
    }

    /**
     * Redacts the query text for logging when $logQueries is false, so the
     * debug/error trade-off documented on each subclass's constructor is
     * enforced in one place rather than repeated at every log call.
     */
    private function queryForLog(string $query): string
    {
        return $this->logQueries ? $query : '[query text redacted]';
    }

    /**
     * Single choke point for every statement actually run against the
     * database. Optional observers can be registered by an integration layer;
     * the Database component itself has no profiler dependency.
     *
     * Wrapped in try/finally rather than try/catch so a failing query is
     * still recorded (with $failed=true) for debugging slow-then-failing
     * queries, without swallowing or altering the original exception —
     * it's simply rethrown untouched after the finally block runs.
     */
    private function executeTracked(string $query, array $params, string $method): \PDOStatement
    {
        $observer = QueryObserverRegistry::current();
        if ($observer === null) {
            return $this->executor->execute($query, $this->normalizeParams($params));
        }

        $start = microtime(true);
        $failed = false;

        try {
            return $this->executor->execute($query, $this->normalizeParams($params));
        } catch (\Throwable $e) {
            $failed = true;
            throw $e;
        } finally {
            $observer->record(
                sql: $this->queryForLog($query),
                paramCount: count($params),
                durationMs: (microtime(true) - $start) * 1000,
                method: $method,
                failed: $failed,
                sqlAvailable: $this->logQueries,
                startedAt: $start,
            );
        }
    }

    /**
     * PDOStatement::execute(array) always binds every value as PDO::PARAM_STR,
     * even with native (non-emulated) prepared statements — there is no way
     * to pass a per-value type through the array shorthand. A PHP bool
     * therefore becomes the literal string '1' or '' rather than 1 or 0,
     * which a strict-mode MySQL TINYINT column rejects outright ('' is not
     * a valid integer) instead of the caller's evidently-intended 0.
     *
     * Converting to int here — once, at the single choke point every write
     * and read passes through — sends '1'/'0' instead: a valid integer
     * literal for MySQL/SQLite, and one of PostgreSQL's accepted textual
     * boolean literals for a real boolean column. Values of any other type
     * (including null, already bound correctly) pass through unchanged.
     *
     * @param mixed[] $params
     * @return mixed[]
     */
    private function normalizeParams(array $params): array
    {
        foreach ($params as $key => $value) {
            if (is_bool($value)) {
                $params[$key] = (int) $value;
            }
        }

        return $params;
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect(): void
    {
        $this->connections->disconnect();
        $this->statements->clear();
        $this->transactions->reset();
    }

    /**
     * {@inheritdoc}
     */
    public function transaction(callable $callback): mixed
    {
        $this->connect();
        $this->beginTransaction();

        try {
            // Pass $this so the callback isn't forced to capture the database via
            // `use ($db)` — a mistake there (capturing a *different* instance) would
            // silently run operations outside this transaction. Callbacks written
            // with zero parameters keep working: PHP ignores extra arguments a
            // closure doesn't declare.
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            try {
                $this->rollback();
            } catch (\Throwable $rollbackException) {
                $this->logger->error('Rollback failed during transaction: ' . $rollbackException->getMessage());
            }
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function quoteIdentifier(string $identifier): string
    {
        return $this->identifiers->quote($identifier);
    }

    /**
     * Destructor: ensures connection is closed.
     *
     * Wrapped defensively: PHP does not guarantee object destruction order during
     * shutdown, so the collaborators this delegates to could conceivably already be
     * torn down (circular references, GC timing) — a failure here must never
     * surface as a fatal error during shutdown.
     */
    public function __destruct()
    {
        try {
            $this->disconnect();
        } catch (\Throwable) {
            // Best-effort cleanup only; nothing meaningful to do with this during shutdown.
        }
    }
}
