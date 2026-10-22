<?php
declare(strict_types=1);

namespace Zara\Core\Storage;

use Zara\Core\Storage\Exceptions\PathTraversalException;

final class PathResolver implements PathResolverInterface
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $realRoot = realpath($root);
        if ($realRoot === false) {
            throw new \InvalidArgumentException(sprintf('Storage root does not exist: "%s"', $root));
        }
        $this->root = $realRoot === DIRECTORY_SEPARATOR
            ? DIRECTORY_SEPARATOR
            : rtrim($realRoot, DIRECTORY_SEPARATOR);
    }

    public function resolve(string $path): string
    {
        if (str_contains($path, "\0")) {
            throw new PathTraversalException($path);
        }

        $normalized = ltrim(str_replace('\\', '/', $path), '/');
        $segments = explode('/', $normalized);
        if (in_array('..', $segments, true)) {
            throw new PathTraversalException($path);
        }

        $normalized = implode(DIRECTORY_SEPARATOR, array_filter(
            $segments,
            static fn (string $segment): bool => $segment !== '' && $segment !== '.',
        ));
        $candidate = $this->root . ($this->root === DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR) . $normalized;

        $real = realpath($candidate);
        if ($real !== false) {
            if (!$this->isWithinRoot($real) || $real === $this->root) {
                throw new PathTraversalException($path);
            }
            return $real;
        }

        $missingSegments = [];
        $existingPath = $candidate;
        while (($existingReal = realpath($existingPath)) === false) {
            $segment = basename($existingPath);
            if ($segment === '' || $segment === '.' || $segment === DIRECTORY_SEPARATOR) {
                throw new PathTraversalException($path);
            }
            array_unshift($missingSegments, $segment);
            $parent = dirname($existingPath);
            if ($parent === $existingPath) {
                throw new PathTraversalException($path);
            }
            $existingPath = $parent;
        }

        if (!$this->isWithinRoot($existingReal)) {
            throw new PathTraversalException($path);
        }

        foreach ($missingSegments as $segment) {
            if (is_link($existingReal . DIRECTORY_SEPARATOR . $segment)) {
                throw new PathTraversalException($path);
            }
            $existingReal .= DIRECTORY_SEPARATOR . $segment;
        }

        if ($existingReal === $this->root) {
            throw new PathTraversalException($path);
        }

        return $existingReal;
    }

    public function root(): string
    {
        return $this->root;
    }

    private function isWithinRoot(string $path): bool
    {
        if ($this->root === DIRECTORY_SEPARATOR) {
            return str_starts_with($path, DIRECTORY_SEPARATOR);
        }

        return $path === $this->root || str_starts_with($path, $this->root . DIRECTORY_SEPARATOR);
    }
}