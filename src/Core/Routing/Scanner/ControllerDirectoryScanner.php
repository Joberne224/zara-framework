<?php

declare(strict_types=1);

namespace Zara\Core\Routing\Scanner;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Scans a directory for PHP controller files and resolves their fully qualified class names (FQCN).
 */
final class ControllerDirectoryScanner
{
    /**
     * Scans a directory and returns a list of FQCN class strings matching PSR-4 mapping.
     *
     * @param string $directory Absolute path to the controller directory.
     * @param string $baseNamespace Root namespace mapped to the directory (e.g., 'App\Controller').
     * @return list<class-string>
     */
    public function scan(string $directory, string $baseNamespace): array
    {
        if (!is_dir($directory)) {
            throw new RuntimeException(
                sprintf("Controller directory scanner error: Directory '%s' does not exist.", $directory)
            );
        }

        $realDirectory = realpath($directory);
        if ($realDirectory === false) {
            throw new RuntimeException(
                sprintf("Controller directory scanner error: Unable to resolve path '%s'.", $directory)
            );
        }

        $classes = [];
        $baseNamespace = trim($baseNamespace, '\\');

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($realDirectory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $relativePath = substr($file->getPathname(), strlen($realDirectory));
                $classRelative = str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], $relativePath);
                
                /** @var class-string $fqcn */
                $fqcn = $baseNamespace . '\\' . ltrim($classRelative, '\\');

                if (class_exists($fqcn)) {
                    $classes[] = $fqcn;
                }
            }
        }

        return $classes;
    }
}