<?php
declare(strict_types=1);

namespace Zara\View\Asset;

use RuntimeException;

/**
 * Resolves template asset paths to physically verified files within public/,
 * enforcing strict path traversal and symlink checks.
 */
final class AssetResolver implements AssetResolverInterface
{
    private string $publicBaseDir;
    private string $assetsBaseDir;

    public function __construct(string $projectRoot)
    {
        $publicPath = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . 'public';
        $resolvedPublic = realpath($publicPath);

        if ($resolvedPublic === false) {
            throw new RuntimeException(
                sprintf("Public directory not found: '%s'.", $publicPath)
            );
        }

        $this->publicBaseDir = $resolvedPublic;
        $this->assetsBaseDir = $resolvedPublic . DIRECTORY_SEPARATOR . 'assets';
    }

    /**
     * Resolves the asset and returns its path relative to public/
     */
    public function resolve(string $path): string
    {
        if (str_contains($path, '://')) {
            throw new RuntimeException("Stream wrappers are not allowed in asset paths.");
        }

        $sanitized = str_replace("\0", '', $path);
        
        // A leading slash means the path is relative to public/ itself.
        $isRootRelative = str_starts_with($sanitized, '/') || str_starts_with($sanitized, '\\');

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sanitized);
        $trimmed    = ltrim($normalized, DIRECTORY_SEPARATOR);

        // Select the base directory according to the path convention.
        $baseDir   = $isRootRelative ? $this->publicBaseDir : $this->assetsBaseDir;
        $candidate = $baseDir . DIRECTORY_SEPARATOR . $trimmed;
        $canonical = $this->canonicalize($candidate);

        // The public directory is always the outermost allowed boundary.
        $withinBounds = $canonical === $this->publicBaseDir
            || str_starts_with($canonical, $this->publicBaseDir . DIRECTORY_SEPARATOR);

        if (!$withinBounds) {
            throw new RuntimeException(
                sprintf("Path traversal attempt detected: '%s' escapes the public directory.", $path)
            );
        }

        if (!is_file($canonical)) {
            throw new RuntimeException(
                sprintf("Asset file not found: '%s' (resolved path: '%s').", $path, $canonical)
            );
        }

        $real = realpath($canonical);
        if ($real === false || !str_starts_with($real, $this->publicBaseDir . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException(
                sprintf("Path traversal attempt detected via symbolic link: '%s'.", $path)
            );
        }

        // Return the path relative to public/ (for example, assets/css/site.css).
        $relativePath = substr($real, strlen($this->publicBaseDir) + 1);

        return str_replace('\\', '/', $relativePath);
    }

    private function canonicalize(string $path): string
    {
        $parts    = array_filter(explode(DIRECTORY_SEPARATOR, $path), 'strlen');
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
}
