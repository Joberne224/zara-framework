<?php
declare(strict_types=1);

namespace Zara\View\Cache;

use InvalidArgumentException;
use RuntimeException;
use Zara\View\Cache\Internal\CachePathGuard;
use Zara\View\Cache\Internal\CacheFileScanner;
use Zara\View\Cache\Internal\CacheSizeEnforcer;
use Zara\View\Cache\Internal\CacheEraser;
use Zara\View\Cache\Internal\CacheFileWriter;
use Zara\View\Cache\Internal\CacheValidator;
use Zara\View\Cache\Internal\CompiledCodeInspector;

/**
 * Orchestrates the lifecycle of the compiled template cache: validity
 * checks, atomic writes, safety inspection, size enforcement, and
 * teardown. Each concern is delegated to a focused, independently
 * testable collaborator under Zara\View\Cache\Internal.
 */
final class CacheManager
{
    private readonly CachePathGuard $pathGuard;
    private readonly CacheFileScanner $scanner;
    private readonly CacheSizeEnforcer $sizeEnforcer;
    private readonly CacheEraser $eraser;
    private readonly CacheFileWriter $writer;
    private readonly CacheValidator $validator;
    private readonly CompiledCodeInspector $inspector;

    /**
     * @throws InvalidArgumentException If a parameter is structurally invalid.
     * @throws RuntimeException         If the cache directory cannot be created or is not writable.
     */
    public function __construct(
        string $cachePath,
        int $maxCacheSize  = 100 * 1024 * 1024,  // 100 MB
        int $maxCacheFiles = 1000,
        ?CacheFileScanner $scanner = null,
        ?CompiledCodeInspector $inspector = null,
        ?CacheValidator $validator = null
    ) {
        if (trim($cachePath) === '') {
            throw new InvalidArgumentException('Cache path cannot be empty.');
        }

        if ($maxCacheSize <= 0) {
            throw new InvalidArgumentException("maxCacheSize must be a positive integer, got: {$maxCacheSize}");
        }

        if ($maxCacheFiles <= 0) {
            throw new InvalidArgumentException("maxCacheFiles must be a positive integer, got: {$maxCacheFiles}");
        }

        $this->pathGuard    = new CachePathGuard($cachePath);
        $this->scanner      = $scanner ?? new CacheFileScanner();
        $this->sizeEnforcer = new CacheSizeEnforcer($maxCacheSize, $maxCacheFiles);
        $this->eraser       = new CacheEraser($this->scanner);
        $this->writer       = new CacheFileWriter($this->pathGuard);
        $this->validator    = $validator ?? new CacheValidator();
        $this->inspector    = $inspector ?? new CompiledCodeInspector();
    }

    /**
     * Determines whether the cache file is still valid.
     */
    public function isValid(string $templatePath, string $cacheFile): bool
    {
        $this->pathGuard->assertPathIsWithinCache($cacheFile);

        return $this->validator->isValid($templatePath, $cacheFile);
    }

    /**
     * Writes the compiled content atomically, after a static safety check.
     */
    public function write(string $cacheFile, string $compiled): void
    {
        $this->pathGuard->assertPathIsWithinCache($cacheFile);

        $safetyReport = $this->inspector->inspect($compiled);
        if (!$safetyReport['safe']) {
            throw new RuntimeException(
                "Refusing to cache compiled template '{$cacheFile}': {$safetyReport['reason']}"
            );
        }

        $this->writer->write($cacheFile, $compiled);
    }

    /**
     * Purges oldest cache files if size or count limits are exceeded.
     */
    public function manageSize(): void
    {
        $this->sizeEnforcer->enforce($this->scanner->listCompiledFiles($this->pathGuard->cachePath()));
    }

    /**
     * Clears all cached files and subdirectories recursively.
     */
    public function clear(): void
    {
        $this->eraser->deleteFiles($this->scanner->listCompiledFiles($this->pathGuard->cachePath()));
        $this->eraser->purgeEmptyDirectories($this->pathGuard->cachePath());
    }

    /**
     * Deletes a single cache file.
     */
    public function deleteFile(string $cacheFile): void
    {
        $this->pathGuard->assertPathIsWithinCache($cacheFile);
        $this->eraser->deleteFile($cacheFile);
    }
}