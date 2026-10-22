<?php

declare(strict_types=1);

namespace Zara\Core\Console;

use JsonException;
use RuntimeException;

/**
 * Generates files from a GenerationContext by resolving a path, rendering a
 * stub, and writing the result. CLI orchestration remains in MakeCommand.
 */
final class StubFileGenerator
{
    public function __construct(
        private readonly string $basePath,
        private readonly StubResolver $stubResolver,
    ) {}

    public function getAppRootNamespace(): string
    {
        return $this->stubResolver->getAppRootNamespace();
    }

    public function getAppSourcePath(): string
    {
        $composerPath = rtrim($this->basePath, '/') . '/composer.json';

        if (!is_file($composerPath)) {
            return 'src';
        }

        $composerJson = file_get_contents($composerPath);
        if ($composerJson === false) {
            throw new RuntimeException("Unable to read file: {$composerPath}");
        }

        try {
            $composer = json_decode($composerJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Invalid composer.json file: {$composerPath}", previous: $e);
        }

        if (!is_array($composer)) {
            throw new RuntimeException("The composer.json file must contain an object: {$composerPath}");
        }

        $autoload = $composer['autoload'] ?? [];
        if (!is_array($autoload)) {
            throw new RuntimeException("Invalid autoload configuration in: {$composerPath}");
        }

        $mappings = $autoload['psr-4'] ?? [];
        if (!is_array($mappings)) {
            throw new RuntimeException("Invalid PSR-4 mapping in: {$composerPath}");
        }

        $appNamespace = $this->getAppRootNamespace();
        $bestMatch = null;

        foreach ($mappings as $prefix => $paths) {
            if (!is_string($prefix)) {
                continue;
            }

            $normalizedPrefix = rtrim($prefix, '\\');
            if (
                $appNamespace !== $normalizedPrefix
                && !str_starts_with($appNamespace, $normalizedPrefix . '\\')
            ) {
                continue;
            }

            if ($bestMatch !== null && strlen($normalizedPrefix) <= $bestMatch['length']) {
                continue;
            }

            $path = is_array($paths) ? reset($paths) : $paths;
            if (!is_string($path) || $path === '') {
                continue;
            }

            $namespaceSuffix = ltrim(substr($appNamespace, strlen($normalizedPrefix)), '\\');
            $sourcePath = rtrim($path, '/\\');
            if ($namespaceSuffix !== '') {
                $sourcePath .= '/' . str_replace('\\', '/', $namespaceSuffix);
            }

            $bestMatch = [
                'length' => strlen($normalizedPrefix),
                'path' => trim($sourcePath, '/'),
            ];
        }

        return $bestMatch['path'] ?? 'src';
    }

    public function delete(string $relativePath): void
    {
        $absolutePath = $this->resolveAbsolutePath($relativePath);
        $this->assertExistingFile($absolutePath, $relativePath);

        if (!unlink($absolutePath)) {
            throw new RuntimeException("Unable to delete controller file: {$relativePath}");
        }
    }

    public function assertFileExists(string $relativePath): void
    {
        $this->assertExistingFile(
            $this->resolveAbsolutePath($relativePath),
            $relativePath,
        );
    }

    private function assertExistingFile(string $absolutePath, string $relativePath): void
    {
        if (!is_file($absolutePath)) {
            throw new RuntimeException("Controller file does not exist: {$relativePath}");
        }
    }

    public function generate(GenerationContext $context): GeneratedFile
    {
        $absolutePath = $this->resolveAbsolutePath($context->relativeFilePath);

        if (file_exists($absolutePath)) {
            throw new RuntimeException("File already exists: {$context->relativeFilePath}");
        }

        $content = $this->buildContent($context);
        $this->writeFile($absolutePath, $content);

        return new GeneratedFile(
            absoluteFilePath: $absolutePath,
            relativeFilePath: $context->relativeFilePath,
        );
    }

    private function resolveAbsolutePath(string $relativePath): string
    {
        return rtrim($this->basePath, '/') . '/' . ltrim($relativePath, '/');
    }

    private function buildContent(GenerationContext $context): string
    {
        $stubPath = $this->stubResolver->resolve($context->stub);
        $template = file_get_contents($stubPath);

        if ($template === false) {
            throw new RuntimeException("Unable to read stub: {$stubPath}");
        }

        // Class and namespace replacements are always available; context
        // replacements can add to or override them.
        $replacements = array_merge(
            [
                '{{ class }}'     => $context->className,
                '{{ namespace }}' => $context->namespace,
            ],
            $context->replacements,
        );

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $template,
        );
    }

    private function writeFile(string $absolutePath, string $content): void
    {
        $directory = dirname($absolutePath);

        if (!is_dir($directory) && !mkdir($directory, 0755, recursive: true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create directory: {$directory}");
        }

        if (file_put_contents($absolutePath, $content) === false) {
            throw new RuntimeException("Unable to write file: {$absolutePath}");
        }
    }
}