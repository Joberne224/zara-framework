<?php

declare(strict_types=1);

namespace Zara\Core\Console;

use RuntimeException;

final class StubResolver
{
    public function __construct(
        private readonly string $appStubsPath,
        private readonly string $frameworkStubsPath,
        private readonly string $appRootNamespace = 'App'
    ) {}

    public function getBasePath(): string
    {
        return dirname($this->appStubsPath);
    }

    public function getAppRootNamespace(): string
    {
        return rtrim($this->appRootNamespace, '\\');
    }

    /** Resolves a stub name to the absolute path of the matching file. */
    public function resolve(string $stubName): string
    {
        $fileName = $stubName . '.stub';

        $appStubFile = rtrim($this->appStubsPath, '/') . '/' . $fileName;
        $frameworkStubFile = rtrim($this->frameworkStubsPath, '/') . '/' . $fileName;

        // Application stubs take precedence.
        if (file_exists($appStubFile)) {
            return $appStubFile;
        }

        // Fall back to framework stubs.
        if (file_exists($frameworkStubFile)) {
            return $frameworkStubFile;
        }

        throw new RuntimeException(
            "Stub '{$stubName}' ({$fileName}) was not found in the application "
            . "({$this->appStubsPath}) or framework ({$this->frameworkStubsPath}).",
        );
    }
}