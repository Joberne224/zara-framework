<?php

declare(strict_types=1);

namespace Zara\Database\Migrations\Internal;

/**
 * Lists migration files present on disk in chronological order. The order
 * comes from sorting the Y_m_d_His timestamp prefixes in filenames, rather
 * than maintaining a separate registry (the same principle documented in
 * MakeMigrationCommand).
 */
final class MigrationFileScanner
{
    public function __construct(private readonly string $migrationsPath)
    {
    }

    /**
     * @return list<string> Migration names (filenames without the .php extension), sorted chronologically
     */
    public function scan(): array
    {
        $files = glob(rtrim($this->migrationsPath, '/') . '/*.php') ?: [];
        sort($files, SORT_STRING);

        $names = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$/D', $name) !== 1) {
                continue;
            }
            $realRoot = realpath($this->migrationsPath);
            $realFile = realpath($file);
            if ($realRoot !== false && $realFile !== false && str_starts_with($realFile, rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function pathFor(string $migrationName): string
    {
        if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$/D', $migrationName) !== 1) {
            throw new \InvalidArgumentException('Invalid migration name.');
        }

        return rtrim($this->migrationsPath, '/') . '/' . $migrationName . '.php';
    }
}
