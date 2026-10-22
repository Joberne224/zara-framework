<?php

declare(strict_types=1);

namespace Zara\View\Engine;

use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves template source paths and cache paths with strict path traversal protection.
 */
final class TemplateResolver
{
    private string        $basePath;
    private string        $cachePath;
    public readonly array $allowedExtensions;

    /**
     * In-memory cache mapping a raw template name to its resolved canonical
     * path, so repeated {% include %}/{% extends %} of the same template
     * within a single request skip the extension-scan + file_exists() loop.
     *
     * @var array<string, string>
     */
    private array $resolvedPathCache = [];

    public function __construct(
        string $basePath,
        string $cachePath,
        array $allowedExtensions = ['zara.php', 'html', 'php', 'phtml']
    ) {
        $realBase = realpath($basePath);
        $this->basePath = $realBase !== false 
            ? rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR 
            : rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR;

        $realCache = realpath($cachePath);
        $this->cachePath = $realCache !== false 
            ? rtrim($realCache, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR 
            : rtrim($cachePath, '/\\') . DIRECTORY_SEPARATOR;

        $this->allowedExtensions = $allowedExtensions;

        $this->ensureDirectoryExists($this->cachePath);
    }

    /**
     * Resolves the absolute path of a source template file.
     *
     * Iterates through $allowedExtensions when no extension is provided,
     * returning the canonical path of the first matching file on disk.
     * Results are memoized per raw $template value for the lifetime of
     * this resolver instance.
     *
     * @param string $template Template name or path.
     * @return string Canonical absolute template path.
     * @throws InvalidArgumentException If stream wrapper is used or extension is invalid.
     * @throws RuntimeException If a path traversal attempt is detected.
     */
    public function resolveTemplatePath(string $template): string
    {
        if (isset($this->resolvedPathCache[$template])) {
            return $this->resolvedPathCache[$template];
        }

        if (str_contains($template, '://')) {
            throw new InvalidArgumentException("Stream wrappers are not allowed in template paths.");
        }

        $normalized = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $template), DIRECTORY_SEPARATOR);
        $extension = pathinfo($normalized, PATHINFO_EXTENSION);

        if (empty($extension)) {
            // Try each allowed extension and select the first existing file.
            foreach ($this->allowedExtensions as $ext) {
                $extClean = ltrim($ext, '.');
                $candidateRelative = $normalized . '.' . $extClean;
                $candidateFull = $this->basePath . $candidateRelative;
                $canonicalPath = $this->canonicalize($candidateFull);

                $this->assertWithinBase($canonicalPath, $this->basePath, $template);

                // Return the path as soon as a matching file is found.
                if (file_exists($canonicalPath) && !is_dir($canonicalPath)) {
                    return $this->resolvedPathCache[$template] = $canonicalPath;
                }
            }

            // If no file exists, use the first allowed extension for the missing path.
            $defaultExt = ltrim($this->allowedExtensions[0] ?? 'php', '.');
            $relativePath = $normalized . '.' . $defaultExt;
        } else {
            $hasAllowedExtension = false;
            foreach ($this->allowedExtensions as $allowedExtension) {
                $allowedSuffix = '.' . ltrim($allowedExtension, '.');
                if (str_ends_with(strtolower($normalized), strtolower($allowedSuffix))) {
                    $hasAllowedExtension = true;
                    break;
                }
            }

            if (!$hasAllowedExtension) {
                throw new InvalidArgumentException(
                    sprintf("Invalid template extension '%s'. Allowed extensions: %s.", $extension, implode(', ', $this->allowedExtensions))
                );
            }
            $relativePath = $normalized;
        }

        $fullPath = $this->basePath . $relativePath;
        $canonicalPath = $this->canonicalize($fullPath);

        $this->assertWithinBase($canonicalPath, $this->basePath, $template);

        return $this->resolvedPathCache[$template] = $canonicalPath;
    }

    /**
     * Resolves the absolute path of the target compiled cache file.
     *
     * @param string $template Template name or path.
     * @return string Canonical absolute cache path.
     * @throws InvalidArgumentException If stream wrapper is used.
     * @throws RuntimeException If a path traversal attempt is detected.
     */
    public function resolveCachePath(string $template): string
    {
        if (str_contains($template, '://')) {
            throw new InvalidArgumentException("Stream wrappers are not allowed in template paths.");
        }

        $sourcePath = $this->resolveTemplatePath($template);
        $relativeSourcePath = substr($sourcePath, strlen($this->basePath));
        $fullCacheDir = $this->cachePath . dirname($relativeSourcePath);
        $canonicalCacheDir = $this->canonicalize($fullCacheDir);

        $this->assertWithinBase($canonicalCacheDir, $this->cachePath, $template, allowExactBase: true);

        $this->ensureDirectoryExists($canonicalCacheDir);

        // Keep the source extension in the cache key. `page.html` and
        // `page.php` are distinct templates and must never share compiled code.
        $filename = basename($relativeSourcePath) . '.php';

        return $canonicalCacheDir . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * Resolves '.' and '..' segments without requiring the target file to exist on disk.
     */
    private function canonicalize(string $path): string
    {
        $parts = array_filter(explode(DIRECTORY_SEPARATOR, $path), 'strlen');
        $segments = [];

        foreach ($parts as $part) {
            if ($part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($segments);
            } else {
                $segments[] = $part;
            }
        }

        $prefix = (DIRECTORY_SEPARATOR === '\\') ? '' : DIRECTORY_SEPARATOR;
        return $prefix . implode(DIRECTORY_SEPARATOR, $segments);
    }

    /**
     * Validates that a lexically-canonicalized candidate path stays within
     * $base, guarding against both '..' traversal (handled by
     * canonicalize()) and symlink-based escapes: if the candidate exists
     * on disk, its real (symlink-resolved) path is checked instead of the
     * lexical one, since a symlink inside $base could otherwise point
     * outside it and bypass the string-prefix check. A candidate that
     * doesn't exist yet (e.g. while scanning allowed extensions) falls
     * back to the lexical path — there is nothing on disk to resolve.
     *
     * @param string $canonicalPath Lexically-resolved candidate path.
     * @param string $base Trusted base directory the candidate must stay within (basePath or cachePath).
     * @param string $originalTemplate Original template argument, for the exception message.
     * @param bool $allowExactBase Whether $canonicalPath === rtrim($base) (no trailing separator) counts as valid —
     *                             needed by resolveCachePath() when the template has no subdirectory.
     * @throws RuntimeException If the resolved path escapes $base.
     */
    private function assertWithinBase(
        string $canonicalPath,
        string $base,
        string $originalTemplate,
        bool $allowExactBase = false
    ): void {
        $pathToCheck = realpath($canonicalPath) ?: $canonicalPath;

        $withinBase = str_starts_with($pathToCheck, $base)
            || ($allowExactBase && $pathToCheck === rtrim($base, DIRECTORY_SEPARATOR));

        if (!$withinBase) {
            throw new RuntimeException(
                sprintf("Path traversal attempt detected: template '%s' escapes the base directory.", $originalTemplate)
            );
        }
    }

    /**
     * Creates $dir if missing, tolerating a benign race between concurrent
     * requests (PHP-FPM workers) that both pass the is_dir() check before
     * either mkdir() runs: the second mkdir() call fails only because the
     * first already created the directory, which is not a real failure.
     *
     * @throws RuntimeException If $dir still doesn't exist after the attempt
     *                          (a genuine failure — e.g. permissions).
     */
    private function ensureDirectoryExists(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException(
                sprintf("Failed to create directory: %s", $dir)
            );
        }
    }
}
