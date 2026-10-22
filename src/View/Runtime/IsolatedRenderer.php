<?php

declare(strict_types=1);

namespace Zara\View\Runtime;

use InvalidArgumentException;
use Zara\View\Interfaces\MethodCallGuardInterface;

/**
 * Executes a compiled template file within an isolated variable scope.
 */
final class IsolatedRenderer
{
    private readonly string $cacheRoot;

    public function __construct(string $cacheRoot)
    {
        $realRoot = realpath($cacheRoot);
        if ($realRoot === false || !is_dir($realRoot)) {
            throw new InvalidArgumentException('The compiled template cache root must be an existing directory.');
        }

        $this->cacheRoot = rtrim(str_replace('\\', '/', $realRoot), '/') . '/';
    }

    private const RESERVED_KEYS = [
        '__cachePath',
        '__vars',
        '__filters',
        '__callableFunctions',
        '__key',
        '__value',
        '__name',
        '__callback',
        '__zaraCallGuard',
        '__cacheRoot',
        'realCachePath',
        'normalizedPath',
        'normalizedRoot',
        'pathToCheck',
        'rootToCheck',
        'this',
        'GLOBALS',
        '_GET',
        '_POST',
        '_REQUEST',
        '_SERVER',
        '_COOKIE',
        '_SESSION',
        '_ENV',
        '_FILES',
    ];

    /**
     * Evaluates and includes the compiled template file safely inside a local scope.
     *
     * @throws InvalidArgumentException If a variable name is reserved or cache path is unsafe/invalid.
     */
    public function render(
        string $cachePath,
        array $vars,
        array $filters,
        array $callableFunctions,
        ?MethodCallGuardInterface $callGuard = null
    ): void {
        self::execute($this->cacheRoot, $cachePath, $vars, $filters, $callableFunctions, $callGuard);
    }

    /**
     * Static execution method preventing '$this' leaking into the executed template file scope.
     */
    private static function execute(
        string $__cacheRoot,
        string $__cachePath,
        array $__vars,
        array $__filters,
        array $__callableFunctions,
        ?MethodCallGuardInterface $__callGuard
    ): void {
        if (str_contains($__cachePath, '://')) {
            throw new InvalidArgumentException("Stream wrappers are strictly forbidden in compiled template path.");
        }

        $realCachePath = realpath($__cachePath);

        if ($realCachePath === false || !is_file($realCachePath) || !is_readable($realCachePath)) {
            throw new InvalidArgumentException(
                sprintf("Compiled template cache file '%s' does not exist or is not readable.", $__cachePath)
            );
        }

        $normalizedPath = str_replace('\\', '/', $realCachePath);
        $normalizedRoot = rtrim(str_replace('\\', '/', $__cacheRoot), '/') . '/';
        $pathToCheck = DIRECTORY_SEPARATOR === '\\' ? strtolower($normalizedPath) : $normalizedPath;
        $rootToCheck = DIRECTORY_SEPARATOR === '\\' ? strtolower($normalizedRoot) : $normalizedRoot;
        if (!str_starts_with($pathToCheck, $rootToCheck)) {
            throw new InvalidArgumentException('Compiled template path is outside the configured cache directory.');
        }

        foreach ($__vars as $__key => $__value) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $__key)) {
                throw new InvalidArgumentException(
                    sprintf("Invalid variable name in template: %s", $__key)
                );
            }
            if (in_array($__key, self::RESERVED_KEYS, true)) {
                throw new InvalidArgumentException(
                    sprintf("Variable name reserved by the engine, forbidden in template data: %s", $__key)
                );
            }
            ${$__key} = $__value;
        }

        foreach (array_merge($__filters, $__callableFunctions) as $__name => $__callback) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $__name)) {
                throw new InvalidArgumentException(
                    sprintf("Invalid filter/function name: %s", $__name)
                );
            }
            if (in_array($__name, self::RESERVED_KEYS, true)) {
                throw new InvalidArgumentException(
                    sprintf("Filter/function name reserved by the engine, forbidden: %s", $__name)
                );
            }
            if (is_callable($__callback)) {
                ${$__name} = $__callback;
            }
        }

        $__zaraCallGuard = $__callGuard;
        unset($__vars, $__filters, $__callableFunctions, $__key, $__value, $__name, $__callback, $__callGuard);

        require $realCachePath;
    }
}
