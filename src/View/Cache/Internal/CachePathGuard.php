<?php
declare(strict_types=1);

namespace Zara\View\Cache\Internal;

use RuntimeException;

/**
 * Resolves and canonicalizes the cache root directory, and guards
 * every cache-relative path against traversal outside of it.
 */
final class CachePathGuard
{
    private readonly string $cachePath;

    public function __construct(string $rawCachePath)
    {
        $resolved   = str_replace('\\', '/', $rawCachePath);
        $normalized = rtrim($resolved, '/') . '/';

        if (!is_dir($normalized)) {
            if (!@mkdir($normalized, 0755, true) && !is_dir($normalized)) {
                throw new RuntimeException("Failed to create cache directory: {$normalized}");
            }
        }
        if (!is_writable($normalized)) {
            throw new RuntimeException("Cache directory is not writable: {$normalized}");
        }

        $realPath = realpath($normalized);
        if ($realPath === false) {
            throw new RuntimeException("Unable to resolve canonical path for: {$normalized}");
        }

        $this->cachePath = str_replace('\\', '/', $realPath) . '/';
    }

    public function cachePath(): string
    {
        return $this->cachePath;
    }

    public function ensureDirectoryIsUsable(string $path): void
    {
        $this->assertPathIsWithinCache($path);

        if (!is_dir($path)) {
            if (!@mkdir($path, 0755, true) && !is_dir($path)) {
                throw new RuntimeException("Failed to create cache directory: {$path}");
            }
        }

        // Re-check after mkdir: an existing symlink in any path component
        // must not redirect cache writes outside the cache root.
        $this->assertPathIsWithinCache($path);

        if (!is_writable($path)) {
            throw new RuntimeException("Cache directory is not writable: {$path}");
        }
    }

    public function assertPathIsWithinCache(string $path): void
    {
        $normalized = str_replace('\\', '/', $path);

        $parts = explode('/', $normalized);
        $safeParts = [];
        foreach ($parts as $part) {
            if ($part === '.' || $part === '') {
                continue;
            }
            if ($part === '..') {
                array_pop($safeParts);
            } else {
                $safeParts[] = $part;
            }
        }

        $canonical = (str_starts_with($normalized, '/') ? '/' : '') . implode('/', $safeParts);

        $cacheRoot = rtrim($this->cachePath, '/');
        $withinLexicalRoot = str_starts_with($canonical, $this->cachePath);

        // Resolve the target when possible. For a not-yet-created file,
        // resolve its nearest existing ancestor to catch symlinked parents.
        $existingPath = $canonical;
        while (!file_exists($existingPath) && !is_link($existingPath)) {
            $parent = dirname($existingPath);
            if ($parent === $existingPath) {
                break;
            }
            $existingPath = $parent;
        }
        $realExistingPath = realpath($existingPath);
        if ($realExistingPath !== false) {
            // realpath() uses native separators on Windows, while both the
            // candidate and cache root above are normalized to '/'.
            $realExistingPath = str_replace('\\', '/', $realExistingPath);
        }
        $withinRealRoot = $realExistingPath !== false
            && ($realExistingPath === $cacheRoot || str_starts_with($realExistingPath, $this->cachePath));

        if (!$withinLexicalRoot || !$withinRealRoot) {
            throw new RuntimeException("Path traversal protection triggered for path: {$path}");
        }
    }
}
