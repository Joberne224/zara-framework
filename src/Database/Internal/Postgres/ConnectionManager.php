<?php

declare(strict_types=1);

namespace Zara\Database\Internal\Postgres;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Zara\Database\Internal\ConnectionInterface;
use Zara\Database\DatabaseConfig;
use Zara\Database\Exceptions\ConnectionException;
use Zara\Database\Internal\PdoProviderInterface;

final class ConnectionManager implements ConnectionInterface, PdoProviderInterface
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly DatabaseConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function connect(): void
    {
        if ($this->pdo !== null) {
            return;
        }

        $dsn = $this->buildDsn();

        $defaultOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        // Exception-mode errors are a load-bearing assumption of this whole
        // layer (reconnect detection, QueryException wrapping): re-imposed
        // after the merge so a caller-supplied option cannot silently opt out.
        $options = array_replace($defaultOptions, $this->config->options);
        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;

        try {
            $pdo = new PDO($dsn, $this->config->username, $this->config->password->reveal(), $options);

            $pdo->exec("SET client_encoding TO '" . $this->effectiveCharset() . "'");

            $this->pdo = $pdo;
            $this->logger->info('Connected to PostgreSQL at ' . $this->config->host . ':' . $this->config->port);
        } catch (PDOException $e) {
            $this->logger->error('Connection failed: ' . $e->getMessage());
            throw new ConnectionException(
                $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Charset names are driver-specific: DatabaseConfig is shared with MySQL and
     * defaults to 'utf8mb4', which PostgreSQL rejects. Its UTF-8 aliases all
     * denote the same encoding, so report PostgreSQL's own name.
     */
    private function effectiveCharset(): string
    {
        return in_array(strtolower($this->config->charset), ['utf8mb4', 'utf8', 'utf'], true)
            ? 'UTF8'
            : $this->config->charset;
    }

    private function buildDsn(): string
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $this->config->host,
            $this->config->port,
            $this->config->dbname,
        );

        if (!$this->requiresTls()) {
            return $dsn;
        }

        $caPath = $this->config->sslCaPath;
        if ($caPath === null || trim($caPath) === '') {
            throw new ConnectionException(
                'TLS with certificate verification is required for remote PostgreSQL connections; configure sslCaPath with the trusted CA certificate path.'
            );
        }
        if (
            str_contains($caPath, "\0")
            || str_contains($caPath, ';')
            || !is_file($caPath)
            || !is_readable($caPath)
        ) {
            throw new ConnectionException(
                'The PostgreSQL TLS CA certificate path must identify a readable file and cannot contain null bytes or semicolons.'
            );
        }

        return $dsn . ';sslmode=verify-full;sslrootcert=' . $caPath;
    }

    private function requiresTls(): bool
    {
        $host = strtolower(trim($this->config->host));

        return !in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            && !str_starts_with($host, '/');
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
        return ['driver' => 'PostgreSQL', 'connected' => $this->pdo !== null, 'host' => $this->config->host, 'port' => $this->config->port, 'database' => $this->config->dbname, 'charset' => $this->effectiveCharset()];
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

    public function forceReconnect(): PDO
    {
        $this->pdo = null;
        $this->connect();

        return $this->getPdo();
    }

    public function isConnectionLostError(PDOException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');

        if (str_starts_with($sqlState, '08')) {
            return true;
        }

        $message = $e->getMessage();

        return str_contains($message, 'server closed the connection unexpectedly')
            || str_contains($message, 'could not connect to server')
            || str_contains($message, 'no connection to the server')
            || str_contains($message, 'terminating connection');
    }
}
