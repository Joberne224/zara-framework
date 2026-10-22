<?php
declare(strict_types=1);

namespace Zara\View\Cache\Internal;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Recursively discovers compiled cache artifacts on disk.
 */
final class CacheFileScanner
{
    /**
     * @return string[] Absolute paths of all compiled (.php) cache files.
     */
    public function listCompiledFiles(string $cachePath): array
    {
        if (!is_dir($cachePath)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cachePath, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', $fileInfo->getPathname());
            }
        }

        return $files;
    }

    /**
     * Child-first iterator over every entry (files and directories),
     * suitable for a full recursive teardown.
     */
    public function iterateChildFirst(string $cachePath): RecursiveIteratorIterator
    {
        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cachePath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
    }
}