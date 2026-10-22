<?php

declare(strict_types=1);

namespace Zara\Database\Internal\Mysql;

use Psr\Log\LoggerInterface;
use Zara\Database\Internal\Shared\StatementCache;
use Zara\Database\Internal\Shared\TransactionManager;
use Zara\Database\Internal\StatementExecutorInterface;

/**
 * Executes a query against the current connection, transparently reconnecting once
 * if the connection was confirmed dead *before* the query was sent.
 *
 * Retrying is only attempted for errors ConnectionManager::isSafeToAutoRetry()
 * confirms happened pre-transmission (MySQL error 2006). A drop detected
 * *during* transmission (2013, "Error while sending", generic "Lost connection
 * to") is left to propagate untouched: the server may already have executed
 * the statement, and a blind retry could duplicate a write. Reconnecting is
 * also only safe when no transaction is open — retrying inside a transaction
 * would silently start over on a fresh connection and lose whatever atomicity
 * guarantee the caller was relying on, so that case is excluded too. When a
 * connection drop kills an open transaction, the depth counter is reset
 * instead: the next beginTransaction() then starts a real transaction rather
 * than SAVEPOINTs against one that no longer exists.
 */
final class StatementExecutor implements StatementExecutorInterface
{
    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly StatementCache $statements,
        private readonly TransactionManager $transactions,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(string $query, array $params, bool $allowRetry = true): \PDOStatement
    {
        $this->connections->connect();
        $pdo = $this->connections->getPdo();

        try {
            $stmt = $this->statements->prepare($pdo, $query);
            $stmt->execute($params);
            return $stmt;
        } catch (\PDOException $e) {
            // Captured before any reset below: the retry decision must know
            // whether the statement belonged to an open transaction.
            $inTransaction = $this->transactions->depth() > 0;

            if ($allowRetry && !$inTransaction && $this->connections->isSafeToAutoRetry($e)) {
                $this->logger->warning('Connection was already dead before the query was sent, reconnecting: ' . $e->getMessage());
                $this->connections->forceReconnect();
                $this->statements->clear();
                return $this->execute($query, $params, false);
            }

            if ($this->connections->isConnectionLostError($e)) {
                if ($inTransaction) {
                    // The open transaction died with the connection: reset the
                    // depth counter so the next beginTransaction() starts a real
                    // transaction instead of emitting SAVEPOINTs against one
                    // that no longer exists.
                    $this->transactions->reset();
                }
                // Detected but not retried: the drop happened during transmission
                // (or inside an open transaction), so whether the statement ran on
                // the server is unknown. Surfacing this distinctly in the log makes
                // it visible that a write may need manual reconciliation, instead
                // of looking like an ordinary query failure.
                $this->logger->error('Connection lost during/after query send — not auto-retried (write outcome unknown): ' . $e->getMessage());
            }

            throw $e;
        }
    }
}