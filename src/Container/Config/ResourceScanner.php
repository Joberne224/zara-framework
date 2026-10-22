<?php

declare(strict_types=1);

namespace Zara\Container\Config;

use ReflectionClass;

final class ResourceScanner
{
    /**
     * Scans a directory to automatically discover instantiable classes.
     *
     * @param string $namespacePrefix Example: "Zara\\"
     * @param string $resource Example: "../src/*" or "../src"
     * @param string|array $exclude Example: "../src/View" or ["../src/View", "../src/Entity"]
     * @param string $projectRoot Project root directory
     * @param string|null $configDir Configuration file directory for relative path resolution
     * @return array<string, array> Map of [FQCN => ['class' => FQCN]]
     */
    public function scan(
        string $namespacePrefix,
        string $resource,
        string|array $exclude,
        string $projectRoot,
        ?string $configDir = null
    ): array {
        $namespacePrefix = rtrim($namespacePrefix, '\\') . '\\';
        $excludeList = is_array($exclude) ? $exclude : [$exclude];

        $baseDir = $this->resolvePath($resource, $projectRoot, $configDir);

        if (!is_dir($baseDir)) {
            return [];
        }

        $resolvedExcludes = array_filter(
            array_map(
                fn(string $ex) => $this->resolvePath($ex, $projectRoot, $configDir),
                $excludeList
            )
        );

        $discoveredServices = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $realPath = $file->getRealPath();
            if ($realPath === false) {
                continue;
            }

            // Apply exclusions.
            if ($this->isExcluded($realPath, $resolvedExcludes)) {
                continue;
            }

            // Convert the relative path to an FQCN.
            $relativePath = substr($realPath, strlen($baseDir));
            $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
            $classWithoutExt = preg_replace('/\.php$/', '', $relativePath);
            
            if ($classWithoutExt === null) {
                continue;
            }

            $fqcn = $namespacePrefix . str_replace('/', '\\', $classWithoutExt);

            if (!class_exists($fqcn)) {
                continue;
            }

            $ref = new ReflectionClass($fqcn);
            
            // Ignore interfaces, traits, abstract classes, and enums.
            if (!$ref->isInstantiable()) {
                continue;
            }

            $discoveredServices[$fqcn] = [
                'class' => $fqcn,
            ];
        }

        return $discoveredServices;
    }

    private function resolvePath(string $path, string $projectRoot, ?string $configDir): string
    {
        $clean = str_replace(['*', '?'], '', $path);
        $clean = str_replace('\\', '/', $clean);

        // If the path is already absolute.
        if (str_starts_with($clean, '/') || preg_match('/^[a-zA-Z]:[\/\\\\]/', $clean)) {
            return realpath($clean) ?: $clean;
        }

        // 1. Try relative to the configuration directory (e.g. configuration/services.yaml).
        if ($configDir !== null) {
            $candidate = rtrim(str_replace('\\', '/', $configDir), '/') . '/' . ltrim($clean, '/');
            $real = realpath($candidate);
            if ($real !== false) {
                return $real;
            }
        }

        // 2. Fall back to the project root.
        $candidate = rtrim(str_replace('\\', '/', $projectRoot), '/') . '/' . ltrim($clean, '/');
        return realpath($candidate) ?: $candidate;
    }

    private function isExcluded(string $filePath, array $excludedPaths): bool
    {
        foreach ($excludedPaths as $excludedPath) {
            $excludedPath = rtrim($excludedPath, DIRECTORY_SEPARATOR);
            if ($excludedPath !== '' && ($filePath === $excludedPath || str_starts_with($filePath, $excludedPath . DIRECTORY_SEPARATOR))) {
                return true;
            }
        }
        return false;
    }
}
