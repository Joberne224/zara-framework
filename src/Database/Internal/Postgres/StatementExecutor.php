<?php

declare(strict_types=1);

namespace Zara\Database\Internal\Postgres;

use PDOException;
use PDOStatement;
use Psr\Log\LoggerInterface;
use Zara\Database\Internal\Shared\StatementCache;
use Zara\Database\Internal\Shared\TransactionManager;
use Zara\Database\Internal\StatementExecutorInterface;

/**
 * Executes a query against the current connection, transparently reconnecting
 * once when the connection was already dead *before the statement could run*.
 *
 * PostgreSQL has no error code distinguishing "connection was dead before the
 * statement was sent" from "connection dropped while the statement was in
 * flight" (MySQL draws exactly that line between errors 2006 and 2013). So the
 * split is made by *where* the failure surfaces instead:
 *
 *  - prepare() only round-trips the statement *definition* — nothing ever
 *    executes — so re-preparing on a fresh connection cannot duplicate a
 *    write. That is the one case eligible for reconnect + retry.
 *  - execute() failing with a connection-lost error is ambiguous: the server
 *    may already have committed the statement. The connection is replaced (so
 *    later calls don't keep hitting a dead socket) but the statement is never
 *    re-run — a blind retry could duplicate an INSERT/UPDATE/DELETE.
 *
 * In both paths, any open transaction died with the connection: the depth
 * counter is reset so the next beginTransaction() starts clean instead of
 * emitting SAVEPOINTs against a transaction that no longer exists.
 */
final class StatementExecutor implements StatementExecutorInterface
{
    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly StatementCache $statements,
        private readonly TransactionManager $transactions,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(string $query, array $params): PDOStatement
    {
        $this->connections->connect();
        $pdo = $this->connections->getPdo();

        $statement = $this->prepareWithReconnect($pdo, $query);

        try {
            $statement->execute($params);

            return $statement;
        } catch (PDOException $e) {
            if ($this->connections->isConnectionLostError($e)) {
                $this->discardDeadConnection();
                $this->logger->error('Connection lost during/after statement execution — not auto-retried (write outcome unknown): ' . $e->getMessage());
            }

            throw $e;
        }
    }

    private function prepareWithReconnect(\PDO $pdo, string $query): PDOStatement
    {
        try {
            return $this->statements->prepare($pdo, $query);
        } catch (PDOException $e) {
            if (!$this->connections->isConnectionLostError($e)) {
                throw $e;
            }

            if ($this->transactions->depth() > 0) {
                // The statement would have run inside that transaction; re-running
                // it on a fresh connection would silently lose the atomicity
                // guarantee the caller is relying on.
                $this->discardDeadConnection();
                throw $e;
            }

            $this->logger->warning('Connection was already dead before the statement was executed, reconnecting: ' . $e->getMessage());
            $pdo = $this->connections->forceReconnect();
            $this->statements->clear();

            return $this->statements->prepare($pdo, $query);
        }
    }

    /**
     * Drops the dead PDO (lazily reconnected on the next call) and forgets
     * every cached statement bound to it. A failing reconnect must not mask
     * the original error, so its exception is swallowed: the connection
     * manager is already back to a disconnected state either way.
     */
    private function discardDeadConnection(): void
    {
        $this->transactions->reset();
        $this->statements->clear();

        try {
            $this->connections->forceReconnect();
        } catch (\Throwable) {
        }
    }
}
