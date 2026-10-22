<?php
declare(strict_types=1);

namespace Zara\Database\Internal\Sqlite;

final class SqlitePathResolver
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $realRoot = realpath($root);
        if ($realRoot === false || !is_dir($realRoot)) {
            throw new \InvalidArgumentException(sprintf('SQLite root directory does not exist: "%s"', $root));
        }

        $this->root = $realRoot === DIRECTORY_SEPARATOR
            ? DIRECTORY_SEPARATOR
            : rtrim($realRoot, DIRECTORY_SEPARATOR);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function resolve(string $path): string
    {
        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException('SQLite path cannot contain null bytes.');
        }

        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if ($this->isAbsolute($path)) {
            $rootPrefix = $this->root === DIRECTORY_SEPARATOR
                ? DIRECTORY_SEPARATOR
                : $this->root . DIRECTORY_SEPARATOR;
            $comparisonPath = DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
            $comparisonPrefix = DIRECTORY_SEPARATOR === '\\' ? strtolower($rootPrefix) : $rootPrefix;

            if (!str_starts_with($comparisonPath, $comparisonPrefix)) {
                throw new \InvalidArgumentException('SQLite path must be beneath the configured root directory.');
            }

            $path = substr($path, strlen($rootPrefix));
        }

        if (in_array('..', explode(DIRECTORY_SEPARATOR, $path), true)) {
            throw new \InvalidArgumentException('SQLite path cannot traverse outside the configured root directory.');
        }

        $segments = array_values(array_filter(
            explode(DIRECTORY_SEPARATOR, $path),
            static fn (string $segment): bool => $segment !== '' && $segment !== '.',
        ));
        if ($segments === []) {
            throw new \InvalidArgumentException('SQLite path must identify a file beneath the configured root.');
        }

        $candidate = $this->root
            . ($this->root === DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR)
            . implode(DIRECTORY_SEPARATOR, $segments);

        $real = realpath($candidate);
        if ($real !== false) {
            if (!$this->isWithinRoot($real) || $real === $this->root) {
                throw new \InvalidArgumentException('SQLite path resolves outside the configured root directory.');
            }

            return $real;
        }

        $missingSegments = [];
        $existingPath = $candidate;
        while (($existingReal = realpath($existingPath)) === false) {
            $segment = basename($existingPath);
            if ($segment === '' || $segment === '.' || $segment === DIRECTORY_SEPARATOR) {
                throw new \InvalidArgumentException('SQLite path cannot be resolved beneath the configured root.');
            }

            array_unshift($missingSegments, $segment);
            $parent = dirname($existingPath);
            if ($parent === $existingPath) {
                throw new \InvalidArgumentException('SQLite path cannot be resolved beneath the configured root.');
            }
            $existingPath = $parent;
        }

        if (!$this->isWithinRoot($existingReal)) {
            throw new \InvalidArgumentException('SQLite path resolves outside the configured root directory.');
        }

        foreach ($missingSegments as $segment) {
            $nextPath = $existingReal . DIRECTORY_SEPARATOR . $segment;
            if (is_link($nextPath)) {
                throw new \InvalidArgumentException('SQLite path cannot traverse a symbolic link.');
            }
            $existingReal = $nextPath;
        }

        if ($existingReal === $this->root) {
            throw new \InvalidArgumentException('SQLite path must identify a file beneath the configured root.');
        }

        return $existingReal;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:' . preg_quote(DIRECTORY_SEPARATOR, '/') . '/', $path) === 1;
    }

    private function isWithinRoot(string $path): bool
    {
        if ($this->root === DIRECTORY_SEPARATOR) {
            return str_starts_with($path, DIRECTORY_SEPARATOR);
        }

        return $path === $this->root || str_starts_with($path, $this->root . DIRECTORY_SEPARATOR);
    }
}
