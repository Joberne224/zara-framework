<?php
//File: zara-framework/src/Database/Internal/Sqlite/ConnectionManager.php
declare(strict_types=1);

namespace Zara\Database\Internal\Sqlite;

use PDO;
use PDOException;
use Zara\Database\Internal\ConnectionInterface;
use Zara\Database\Exceptions\ConnectionException;
use Zara\Database\Internal\PdoProviderInterface;
use Zara\Database\SqliteConfig;

final class ConnectionManager implements ConnectionInterface, PdoProviderInterface
{
    private ?PDO $pdo = null;

    public function __construct(private readonly SqliteConfig $config)
    {
    }

    public function connect(): void
    {
        if ($this->pdo !== null) {
            return;
        }

        $dsn = $this->config->isInMemory()
            ? 'sqlite::memory:'
            : 'sqlite:' . $this->config->pathForConnection();

        $defaultOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        // Exception-mode errors are a load-bearing assumption of this whole
        // layer (QueryException wrapping): re-imposed after the merge so a
        // caller-supplied option cannot silently opt out.
        $options = array_replace($defaultOptions, $this->config->options);
        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;

        try {
            // Kept local until every setup step below succeeds: assigning to
            // $this->pdo only at the end means a PRAGMA failure can't leave the
            // connect() guard above ("if already connected, return") short-
            // circuiting a future retry on a half-configured connection.
            $pdo = new PDO($dsn, null, null, $options);
            $pdo->exec('PRAGMA busy_timeout = ' . $this->config->busyTimeoutMs);

            if ($this->config->foreignKeys) {
                $pdo->exec('PRAGMA foreign_keys = ON');
            }

            $this->pdo = $pdo;
        } catch (PDOException $e) {
            throw new ConnectionException('Unable to connect to SQLite database: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            throw new ConnectionException('Not connected: call connect() before getPdo().');
        }

        return $this->pdo;
    }

    public function getProfilerInfo(): array
    {
        return ['driver' => 'SQLite', 'connected' => $this->pdo !== null, 'host' => null, 'port' => null, 'database' => $this->config->isInMemory() ? ':memory:' : basename($this->config->path), 'charset' => null];
    }

    public function disconnect(): void
    {
        $this->pdo = null;
    }

    public function isConnected(): bool
    {
        if ($this->pdo === null) {
            return false;
        }

        try {
            $this->pdo->query('SELECT 1');

            return true;
        } catch (PDOException) {
            return false;
        }
    }
}
