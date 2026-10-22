<?php

declare(strict_types=1);

namespace Zara\Database\Migrations\Internal;

use Zara\Database\Migrations\Exception\MigrationClassNotFoundException;
use Zara\Database\Migrations\Exception\MigrationFileInvalidException;
use Zara\Database\Migrations\Migration;

/**
 * Resolves a migration name to a Migration instance.
 *
 * Migration files are intentionally not PSR-4 autoloaded (they live outside
 * src/, in database/migrations/). They are explicitly loaded with require_once,
 * then the expected class name is reconstructed through MigrationNameResolver,
 * the single source of truth shared with MakeMigrationCommand for the
 * filename-to-class mapping.
 */
final class MigrationClassResolver
{
    public function __construct(
        private readonly string $migrationNamespace,
        private readonly ?string $migrationsPath = null,
    ) {}

    public function resolve(string $filePath, string $migrationName): Migration
    {
        if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$/D', $migrationName) !== 1
            || basename($filePath) !== $migrationName . '.php') {
            throw new MigrationFileInvalidException('Invalid migration file or name.');
        }

        $realFile = realpath($filePath);
        if ($realFile === false || !is_file($realFile) || !is_readable($realFile)) {
            throw new MigrationFileInvalidException('Migration file does not exist or is not readable.');
        }
        if ($this->migrationsPath !== null) {
            $realRoot = realpath($this->migrationsPath);
            if ($realRoot === false || !str_starts_with($realFile, rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                throw new MigrationFileInvalidException('Migration file is outside the configured migrations directory.');
            }
        }

        require_once $realFile;

        $className = MigrationNameResolver::classNameFromMigrationName($migrationName);
        $fqcn = rtrim($this->migrationNamespace, '\\') . '\\' . $className;

        if (!class_exists($fqcn, autoload: false)) {
            throw new MigrationClassNotFoundException(
                "Class '{$fqcn}' was not found after including '{$filePath}'. "
                    . "Check that the file was not renamed manually."
            );
        }

        $instance = new $fqcn();

        if (!$instance instanceof Migration) {
            throw new MigrationClassNotFoundException(
                "'{$fqcn}' must extend " . Migration::class . '.'
            );
        }

        return $instance;
    }
}
