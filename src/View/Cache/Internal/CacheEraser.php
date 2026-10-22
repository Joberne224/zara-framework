<?php
declare(strict_types=1);

namespace Zara\View\Cache\Internal;

use RuntimeException;

/**
 * Deletes compiled cache artifacts and tears down the directory tree
 * left behind once they're gone.
 */
final class CacheEraser
{
    public function __construct(private readonly CacheFileScanner $scanner)
    {
    }

    /**
     * @param string[] $files
     */
    public function deleteFiles(array $files): void
    {
        foreach ($files as $file) {
            if (!@unlink($file) && file_exists($file)) {
                throw new RuntimeException("Failed to delete cache file: {$file}");
            }
        }
    }

    public function deleteFile(string $cacheFile): void
    {
        if (file_exists($cacheFile)) {
            if (!@unlink($cacheFile)) {
                throw new RuntimeException("Failed to delete cache file: {$cacheFile}");
            }
        }
    }

    public function purgeEmptyDirectories(string $cachePath): void
    {
        if (!is_dir($cachePath)) {
            return;
        }

        foreach ($this->scanner->iterateChildFirst($cachePath) as $item) {
            if ($item->isDir()) {
                $dirPath = $item->getPathname();
                if (!@rmdir($dirPath) && is_dir($dirPath)) {
                    throw new RuntimeException("Failed to delete cache directory: {$dirPath}");
                }
            }
        }
    }
}