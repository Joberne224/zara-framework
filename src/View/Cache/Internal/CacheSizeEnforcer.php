<?php
declare(strict_types=1);

namespace Zara\View\Cache\Internal;

/**
 * Applies file-count and total-size limits to a set of cache files,
 * evicting the oldest entries first (LRU-by-mtime).
 */
final class CacheSizeEnforcer
{
    public function __construct(
        private readonly int $maxCacheSize,
        private readonly int $maxCacheFiles
    ) {
    }

    /**
     * @param string[] $files Absolute paths of candidate cache files.
     */
    public function enforce(array $files): void
    {
        $fileCount = count($files);
        $totalSize = 0;
        $fileTimes = [];

        foreach ($files as $file) {
            $size = filesize($file);
            $time = filemtime($file);

            if ($size === false || $time === false) {
                continue;
            }

            $totalSize += $size;
            $fileTimes[$file] = $time;
        }

        if ($fileCount <= $this->maxCacheFiles && $totalSize <= $this->maxCacheSize) {
            return;
        }

        asort($fileTimes);
        foreach ($fileTimes as $file => $time) {
            if ($fileCount <= $this->maxCacheFiles && $totalSize <= $this->maxCacheSize) {
                break;
            }

            $fileSize = filesize($file);
            if ($fileSize === false) {
                $fileCount--;
                continue;
            }

            if (@unlink($file)) {
                $totalSize -= $fileSize;
                $fileCount--;
            }
        }
    }
}