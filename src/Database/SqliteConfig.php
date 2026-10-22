<?php
//File: zara-framework/src/Database/SqliteConfig.php
declare(strict_types=1);

namespace Zara\Database;

use Zara\Database\Internal\Sqlite\SqlitePathResolver;

/**
 * Configuration dedicated to the SQLite driver.
 *
 * Deliberately separate from DatabaseConfig: host/port/username/password/charset
 * do not apply to a file-based engine. Each concrete driver owns the config
 * shape that actually matches its connection semantics.
 *
 * File-backed databases are confined to $rootDirectory, which defaults to
 * the current working directory. Absolute paths are accepted only beneath it.
 *
 * Beware with ':memory:': the database lives inside the PDO connection, so
 * any disconnect() — including the implicit one when the SqliteDatabase
 * object is destroyed — discards it, and a later reconnect starts on an
 * empty database. Use a file path for anything that must survive.
 */
final class SqliteConfig
{
    public readonly string $path;
    public readonly array $options;
    public readonly bool $foreignKeys;
    public readonly int $busyTimeoutMs;
    private readonly ?string $rootDirectory;

    public function __construct(
        string $path,
        array $options = [],
        bool $foreignKeys = true,
        int $busyTimeoutMs = 5000,
        ?string $rootDirectory = null,
    ) {
        if (trim($path) === '') {
            throw new \InvalidArgumentException('SQLite path cannot be empty.');
        }

        if ($busyTimeoutMs < 0) {
            throw new \InvalidArgumentException('busyTimeoutMs cannot be negative.');
        }

        if ($path === ':memory:') {
            $this->path = $path;
            $this->rootDirectory = null;
        } else {
            $rootDirectory ??= getcwd();
            if ($rootDirectory === false) {
                throw new \RuntimeException('Unable to determine the current working directory for SQLite path resolution.');
            }

            $this->rootDirectory = (new SqlitePathResolver($rootDirectory))->root();
            $this->path = self::resolvePath($path, $this->rootDirectory);
        }
        $this->options = $options;
        $this->foreignKeys = $foreignKeys;
        $this->busyTimeoutMs = $busyTimeoutMs;
    }

    public function isInMemory(): bool
    {
        return $this->path === ':memory:';
    }

    public function pathForConnection(): string
    {
        return $this->rootDirectory === null
            ? $this->path
            : self::resolvePath($this->path, $this->rootDirectory);
    }

    private static function resolvePath(string $path, string $rootDirectory): string
    {
        if (
            str_contains($path, "\0")
            || (
                preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $path)
                && preg_match('~^[A-Za-z]:[\\\\/]~', $path) !== 1
            )
        ) {
            throw new \InvalidArgumentException('SQLite path must be a filesystem path, not a URI.');
        }

        return (new SqlitePathResolver($rootDirectory))->resolve($path);
    }
}