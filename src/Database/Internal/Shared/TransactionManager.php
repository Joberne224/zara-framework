<?php

declare(strict_types=1);

namespace Zara\Database\Internal\Shared;

use Psr\Log\LoggerInterface;
use Zara\Database\Exceptions\TransactionException;

/**
 * Tracks transaction nesting depth and translates begin/commit/rollback calls into
 * either a real PDO transaction (outermost level) or a SAVEPOINT (nested levels),
 * so a method wrapped in a transaction can safely call another method that also
 * wraps itself in a transaction.
 */
final class TransactionManager
{
    private int $depth = 0;

    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
    }

    public function depth(): int
    {
        return $this->depth;
    }

    /**
     * @throws TransactionException
     */
    public function begin(\PDO $pdo): void
    {
        try {
            if ($this->depth === 0) {
                $pdo->beginTransaction();
                $this->logger?->info('Transaction started');
            } else {
                $savepoint = $this->savepointName($this->depth);
                $pdo->exec('SAVEPOINT ' . $savepoint);
                $this->logger?->info('Savepoint created: ' . $savepoint);
            }
            $this->depth++;
        } catch (\PDOException $e) {
            $this->logger?->error('Transaction start failed: ' . $e->getMessage());
            throw new TransactionException('Transaction start failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws TransactionException
     */
    public function commit(\PDO $pdo): void
    {
        if ($this->depth === 0) {
            throw new TransactionException('No active transaction to commit');
        }

        // Compute what to do from the depth as it stands *before* touching it: if
        // the PDO operation below throws, $this->depth must still reflect reality
        // (a transaction that failed to commit is still open), not silently look
        // like it was already closed.
        $isOutermost = $this->depth === 1;

        try {
            if ($isOutermost) {
                $pdo->commit();
                $this->logger?->info('Transaction committed');
            } else {
                $savepoint = $this->savepointName($this->depth - 1);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                $this->logger?->info('Savepoint released: ' . $savepoint);
            }
            $this->depth--;
        } catch (\PDOException $e) {
            $this->logger?->error('Transaction commit failed: ' . $e->getMessage());
            throw new TransactionException('Transaction commit failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws TransactionException
     */
    public function rollback(\PDO $pdo): void
    {
        if ($this->depth === 0) {
            throw new TransactionException('No active transaction to roll back');
        }

        $isOutermost = $this->depth === 1;

        try {
            if ($isOutermost) {
                $pdo->rollBack();
                $this->logger?->info('Transaction rolled back');
            } else {
                $savepoint = $this->savepointName($this->depth - 1);
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $this->logger?->info('Rolled back to savepoint: ' . $savepoint);
            }
            $this->depth--;
        } catch (\PDOException $e) {
            $this->logger?->error('Transaction rollback failed: ' . $e->getMessage());
            throw new TransactionException('Transaction rollback failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** Resets to a clean state, e.g. after a disconnect discards the underlying connection. */
    public function reset(): void
    {
        $this->depth = 0;
    }

    private function savepointName(int $depth): string
    {
        return 'zara_sp_' . $depth;
    }
}