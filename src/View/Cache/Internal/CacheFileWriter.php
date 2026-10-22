<?php
declare(strict_types=1);

namespace Zara\View\Cache\Internal;

use RuntimeException;

/**
 * Writes compiled template output to disk atomically, via a
 * temp-file-then-rename sequence.
 */
final class CacheFileWriter
{
    public function __construct(private readonly CachePathGuard $pathGuard)
    {
    }

    public function write(string $cacheFile, string $compiled): void
    {
        $dir = dirname($cacheFile);
        $this->pathGuard->ensureDirectoryIsUsable($dir . '/');

        $tmpFile = $dir . '/.cache_' . bin2hex(random_bytes(16)) . '.tmp';
        $handle = @fopen($tmpFile, 'xb');
        if ($handle === false) {
            throw new RuntimeException("Failed to create temporary cache file: {$tmpFile}");
        }

        try {
            $written = 0;
            $length = strlen($compiled);
            while ($written < $length) {
                $chunk = fwrite($handle, substr($compiled, $written));
                if ($chunk === false || $chunk === 0) {
                    throw new RuntimeException("Failed to write temporary cache file: {$tmpFile}");
                }
                $written += $chunk;
            }
            if (!fflush($handle)) {
                throw new RuntimeException("Failed to flush temporary cache file: {$tmpFile}");
            }
        } catch (\Throwable $error) {
            fclose($handle);
            @unlink($tmpFile);
            throw $error;
        }
        fclose($handle);

        if (!@chmod($tmpFile, 0644)) {
            @unlink($tmpFile);
            throw new RuntimeException("Failed to set permissions on temporary cache file.");
        }

        if (!@rename($tmpFile, $cacheFile)) {
            @unlink($tmpFile);
            throw new RuntimeException("Failed to move cache file into place: {$cacheFile}");
        }
    }
}
