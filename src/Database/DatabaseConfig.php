<?php
//File: zara-framework/src/Database/DatabaseConfig.php
declare(strict_types=1);

namespace Zara\Database;

final class DatabaseConfig
{
    public readonly string $host;
    public readonly string $dbname;
    public readonly string $username;
    public readonly SensitiveValue $password;
    public readonly int $port;
    public readonly string $charset;
    public readonly array $options;
    public readonly ?string $sslCaPath;

    /**
     * Driver-agnostic connection parameters. Building the DSN string is left to each
     * DatabaseInterface implementation (e.g. MysqlDatabase), since the DSN format
     * differs per driver (mysql:, pgsql:, etc.) — this class must stay swappable
     * across drivers without carrying MySQL-specific syntax.
     *
     * @param string $host Database host (cannot be empty)
     * @param string $dbname Database name (cannot be empty)
     * @param string $username Database username (cannot be empty)
     * @param string $password Database password. Wrapped internally in a
     *        SensitiveValue — access the real value via $config->password->reveal(),
     *        never by casting or dumping the property directly.
     * @param int $port Database port (1-65535, default: 3306)
     * @param string $charset Connection charset (default: utf8mb4)
     * @param array $options PDO connection options
     * @param string|null $sslCaPath Trusted CA certificate path for drivers that
     *        require verified TLS for remote connections.
     * @throws \InvalidArgumentException If any parameter is invalid
     */
    public function __construct(
        string $host,
        string $dbname,
        string $username,
        #[\SensitiveParameter] string $password,
        int $port = 3306,
        string $charset = 'utf8mb4',
        array $options = [],
        ?string $sslCaPath = null,
    ) {
        $this->validateHost($host);
        $this->validateDbname($dbname);
        $this->validateUsername($username);
        $this->validatePort($port);
        $this->validateCharset($charset);

        $this->host = $host;
        $this->dbname = $dbname;
        $this->username = $username;
        $this->password = new SensitiveValue($password);
        $this->port = $port;
        $this->charset = $charset;
        $this->options = $options;
        $this->sslCaPath = $sslCaPath;
    }

    /**
     * Validate the host parameter.
     * @throws \InvalidArgumentException
     */
    private function validateHost(string $host): void
    {
        if ($host === '') {
            throw new \InvalidArgumentException('Database host cannot be empty');
        }
        // These characters have structural meaning in a DSN string (key=value
        // pairs separated by ";"); a host value isn't quoted before being
        // interpolated, so allowing them would let a malformed/attacker-controlled
        // value inject extra DSN parameters.
        if (preg_match('/[;=\s]/', $host)) {
            throw new \InvalidArgumentException('Database host contains characters not allowed in a DSN (";", "=", or whitespace)');
        }
    }

    /**
     * Validate the database name parameter.
     * @throws \InvalidArgumentException
     */
    private function validateDbname(string $dbname): void
    {
        if ($dbname === '') {
            throw new \InvalidArgumentException('Database name cannot be empty');
        }
        if (preg_match('/[;=\s]/', $dbname)) {
            throw new \InvalidArgumentException('Database name contains characters not allowed in a DSN (";", "=", or whitespace)');
        }
    }

    /**
     * Validate the username parameter.
     * @throws \InvalidArgumentException
     */
    private function validateUsername(string $username): void
    {
        if ($username === '') {
            throw new \InvalidArgumentException('Database username cannot be empty');
        }
    }

    /**
     * Validate the port parameter.
     * @throws \InvalidArgumentException
     */
    private function validatePort(int $port): void
    {
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Database port must be between 1 and 65535');
        }
    }

    /**
     * Validate the charset parameter. This ends up interpolated into a DSN string
     * by the concrete driver, so it's restricted to the same safe character set as
     * a SQL identifier rather than accepted as an arbitrary string.
     * @throws \InvalidArgumentException
     */
    private function validateCharset(string $charset): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $charset)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid charset: %s. Only alphanumeric characters and underscores are allowed.', $charset)
            );
        }
    }
}