<?php

declare(strict_types=1);

namespace Zara\Database\Internal\Mysql;

use Psr\Log\LoggerInterface;
use Zara\Database\Internal\ConnectionInterface;
use Zara\Database\DatabaseConfig;
use Zara\Database\Exceptions\ConnectionException;
use Zara\Database\Internal\PdoProviderInterface;

/**
 * Owns the single PDO connection: building the DSN, connecting, disconnecting,
 * checking liveness, and recognizing when MySQL has silently dropped the
 * connection so the caller can decide whether to reconnect.
 */
final class ConnectionManager implements ConnectionInterface, PdoProviderInterface
{
    /** MySQL error codes that indicate the connection itself was dropped (any timing). */
    private const CONNECTION_LOST_ERROR_CODES = [2006, 2013];

    /**
     * Error codes safe to automatically retry: the connection was confirmed
     * dead *before* the query was ever sent, so the statement never executed.
     * 2013 ("Lost connection ... during query") is deliberately excluded: the
     * server may have already executed a write when the connection dropped,
     * and a blind retry could duplicate it. StatementExecutor only retries on
     * this narrower set, not on the full isConnectionLostError() set.
     */
    private const SAFE_TO_RETRY_ERROR_CODES = [2006];

    private ?\PDO $pdo = null;

    public function __construct(
        private readonly DatabaseConfig $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function connect(): void
    {
        if ($this->pdo !== null) {
            return;
        }

        // DSN construction is MySQL-specific and therefore belongs here rather than
        // in DatabaseConfig, which stays driver-agnostic so it can be reused by
        // other DatabaseInterface implementations (e.g. a future PostgresDatabase).
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->config->host,
            $this->config->port,
            $this->config->dbname,
            $this->config->charset
        );

        // Merge rather than replace: a caller supplying one custom option (e.g. a
        // custom PDO::ATTR_TIMEOUT) must not silently lose ERRMODE_EXCEPTION and the
        // other defaults the rest of this layer relies on for exception-based errors.
        $options = $this->buildOptions();

        try {
            // ->reveal() is the one sanctioned way to obtain the real password from
            // the SensitiveValue wrapper; everywhere else (logs, dumps, this class's
            // own properties) it stays opaque.
            $this->pdo = new \PDO($dsn, $this->config->username, $this->config->password->reveal(), $options);
            $this->logger->info('Database connection established');
        } catch (\PDOException $e) {
            $driverMessage = str_replace(
                [
                    'Aucune connexion n’a pu être établie car l’ordinateur cible l’a expressément refusée',
                    "Aucune connexion n'a pu être établie car l'ordinateur cible l'a expressément refusée",
                ],
                'No connection could be made because the target computer actively refused it',
                $e->getMessage()
            );
            $message = 'Database connection failed: ' . $driverMessage;

            $this->logger->error($message);
            throw new ConnectionException($message, 0, $e);
        }
    }

    /**
     * Require encrypted, certificate-verified transport for non-local MySQL
     * connections. The CA is deployment-specific, so callers must provide it
     * using PDO::MYSQL_ATTR_SSL_CA in DatabaseConfig::$options.
     */
    private function buildOptions(): array
    {
        $defaultOptions = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        $options = array_replace($defaultOptions, $this->config->options);

        if ($this->requiresTls()) {
            if (
                !defined('PDO::MYSQL_ATTR_SSL_CA')
                || !defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')
            ) {
                throw new ConnectionException(
                    'PDO MySQL TLS options are unavailable; enable the pdo_mysql extension to connect to a remote MySQL server securely.'
                );
            }

            $sslCaOption = constant('PDO::MYSQL_ATTR_SSL_CA');
            $sslVerifyOption = constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT');
            $caPath = $options[$sslCaOption] ?? null;
            if (!is_string($caPath) || trim($caPath) === '') {
                throw new ConnectionException(
                    'TLS is required for remote MySQL connections; configure PDO::MYSQL_ATTR_SSL_CA with the trusted CA certificate path.'
                );
            }

            $options[$sslVerifyOption] = true;
        }

        // Exception-mode errors are a load-bearing assumption of this whole
        // layer (reconnect detection, QueryException wrapping): re-imposed
        // after the merge so a caller-supplied option cannot silently opt out.
        $options[\PDO::ATTR_ERRMODE] = \PDO::ERRMODE_EXCEPTION;

        return $options;
    }

    private function requiresTls(): bool
    {
        return !in_array(strtolower(trim($this->config->host)), [
            'localhost',
            '127.0.0.1',
            '::1',
        ], true);
    }

    /**
     * Returns the underlying PDO instance owned by this manager. Distinct from
     * connect() (ConnectionInterface, the shared database connection lifecycle):
     * this accessor is the narrow, SQL-specific contract
     * (PdoProviderInterface) consumed only by AbstractSqlDatabase/StatementExecutor.
     */
    public function getPdo(): \PDO
    {
        if ($this->pdo === null) {
            throw new ConnectionException('Not connected: call connect() before getPdo().');
        }

        return $this->pdo;
    }

    public function getProfilerInfo(): array
    {
        return ['driver' => 'MySQL', 'connected' => $this->pdo !== null, 'host' => $this->config->host, 'port' => $this->config->port, 'database' => $this->config->dbname, 'charset' => $this->config->charset];
    }

    public function disconnect(): void
    {
        if ($this->pdo !== null) {
            $this->pdo = null;
            $this->logger->info('Database disconnected');
        }
    }

    /**
     * Verifies the connection is actually alive by round-tripping a trivial query,
     * since MySQL can silently drop an idle connection (e.g. wait_timeout) without
     * the PHP object knowing — unlike a plain "is the PDO object set" check.
     *
     * This performs a real network round trip on every call — it is not free, and
     * is not cached. Prefer calling it once before a batch of work rather than
     * before every individual query, and never in a tight loop.
     */
    public function isConnected(): bool
    {
        if ($this->pdo === null) {
            return false;
        }

        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Discards the current PDO instance and immediately opens a fresh one. Used
     * after a detected dropped connection.
     */
    public function forceReconnect(): \PDO
    {
        $this->pdo = null;
        $this->connect();
        return $this->getPdo();
    }

    /**
     * Detects MySQL's "connection dropped by the server" errors (e.g. wait_timeout,
     * network blip) as opposed to a genuine query/data error. Used for diagnostics
     * and logging — NOT the gate for automatic retry, see isSafeToAutoRetry().
     */
    public function isConnectionLostError(\PDOException $e): bool
    {
        $code = $e->errorInfo[1] ?? null;
        if (in_array($code, self::CONNECTION_LOST_ERROR_CODES, true)) {
            return true;
        }

        $message = $e->getMessage();
        return str_contains($message, 'server has gone away')
            || str_contains($message, 'Lost connection to')
            || str_contains($message, 'Error while sending');
    }

    /**
     * Narrower than isConnectionLostError(): true only when the connection was
     * confirmed dead *before* the statement was sent (error 2006, "server has
     * gone away"), so retrying cannot duplicate a write that already executed.
     *
     * Deliberately excludes:
     *  - error 2013 ("Lost connection to MySQL server during query") — the
     *    server may have received and executed the statement before the
     *    connection dropped; retrying could re-run an INSERT/UPDATE/DELETE.
     *  - the "Error while sending" message — the failure happened while the
     *    query bytes were in flight, same ambiguity as 2013.
     *  - the generic "Lost connection to" message — covers the same
     *    mid-transmission ambiguity when it doesn't carry error code 2013.
     */
    public function isSafeToAutoRetry(\PDOException $e): bool
    {
        $code = $e->errorInfo[1] ?? null;
        if (in_array($code, self::SAFE_TO_RETRY_ERROR_CODES, true)) {
            return true;
        }

        return str_contains($e->getMessage(), 'server has gone away');
    }
}
