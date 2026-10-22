<?php

declare(strict_types=1);

namespace Zara\Database\Migrations\Internal;

use Zara\Database\Migrations\Exception\MigrationFileInvalidException;

/**
 * Single source of truth for the mapping between:
 * - the migration name (filename without extension, e.g. "2026_07_30_143022_create_users_table")
 * - the expected class name (e.g. "CreateUsersTable")
 *
 * This uses the exact logic previously used by MakeMigrationCommand
 * (toSnakeCase/toPascalCase), extracted here so file generation and class
 * resolution at execution time can never silently diverge.
 */
final class MigrationNameResolver
{
    private const TIMESTAMP_PATTERN = '/^\d{4}_\d{2}_\d{2}_\d{6}_(.+)$/';

    /**
     * Converts any input casing (PascalCase, kebab-case, space-separated
     * words, etc.) to canonical snake_case.
     */
    public static function toSnakeCase(string $name): string
    {
        $withUnderscores = (string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name);
        $normalized = str_replace(['-', ' '], '_', $withUnderscores);
        $normalized = (string) preg_replace('/_+/', '_', $normalized);

        return strtolower(trim($normalized, '_'));
    }

    /**
     * Derives a PascalCase class name from canonical snake_case.
     */
    public static function toPascalCase(string $snake): string
    {
        $parts = array_filter(explode('_', $snake));

        return implode('', array_map(ucfirst(...), $parts));
    }

    /**
     * Removes the timestamp prefix (Y_m_d_His) from a migration name,
     * leaving only the descriptive snake_case portion.
     */
    public static function extractSnakeName(string $migrationName): string
    {
        if (preg_match(self::TIMESTAMP_PATTERN, $migrationName, $matches) !== 1) {
            throw new MigrationFileInvalidException(
                "Migration name '{$migrationName}' does not match the expected format (Y_m_d_His_name)."
            );
        }

        return $matches[1];
    }

    /**
     * Derives the expected class name directly from the full migration name
     * (including its timestamp prefix).
     */
    public static function classNameFromMigrationName(string $migrationName): string
    {
        return self::toPascalCase(self::extractSnakeName($migrationName));
    }
}
