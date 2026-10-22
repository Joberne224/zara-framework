<?php
declare(strict_types=1);

namespace Zara\View\Registry;

use Zara\View\Asset\AssetResolverInterface;
use Zara\Core\Routing\Interfaces\RouterInterface;

/**
 * Registry for template callable closures and allowed native PHP functions.
 */
final class FunctionRegistry
{
    /** @var array<string, callable> */
    private array $callables = [];

    /** @var array<string, true> */
    private array $allowed = [];

    public function __construct(
        private AssetResolverInterface $assetResolver,
        ?RouterInterface $router = null,
        ?string $baseUrl = null,
    ) { 
        if ($baseUrl === null) {
            $baseUrl = str_replace('/index.php', '', $_SERVER['SCRIPT_NAME'] ?? '');
            $baseUrl = rtrim($baseUrl, '/');
        }

        // 1. Static URL generation
        $this->addCallable('url', function ($path) use ($baseUrl) {
            // Browsers read backslashes as slashes: without this, "/\evil.com"
            // would be treated as the protocol-relative URL "//evil.com".
            $relative = ltrim(str_replace('\\', '/', (string) $path), '/');
            return $baseUrl . '/' . $relative;
        });

        // 2. Asset resolution
        $this->addCallable('asset', function ($path) use ($baseUrl) {
            $path = (string) $path;
            $relative = $this->assetResolver->resolve($path);
            $relative = ltrim(str_replace('\\', '/', $relative), '/');
            return $baseUrl . '/' . $relative;
        });

        // 3. Named route URL generation via Router directly
        if ($router !== null) {
            $this->addCallable('route', function (string $name, array $params = []) use ($router, $baseUrl): string {
                $routePath = $router->route($name, $params);
                $basePath = rtrim(str_replace('\\', '/', $baseUrl), '/');

                if ($basePath === '' || $basePath === '/' || $routePath === $basePath
                    || str_starts_with($routePath, $basePath . '/')) {
                    return $routePath;
                }

                return $basePath . $routePath;
            });
        }

        // String helpers
        $this->addCallable('uppercase', fn (mixed $value): string => strtoupper((string) $value));
        $this->addCallable('lowercase', fn (mixed $value): string => strtolower((string) $value));
        $this->addCallable('capitalize', fn (mixed $value): string => $value === null || $value === '' ? '' : ucfirst((string) $value));
        $this->addCallable('isNumeric', fn (mixed $value): bool => is_numeric($value));

        foreach ([
            'count', 'empty', 'strlen', 'strtolower', 'strtoupper', 'is_string', 'is_numeric',
            'is_array', 'in_array', 'implode', 'explode',
            'str_replace', 'substr', 'trim',
        ] as $name) {
            $this->allow($name);
        }
    }

    public function addCallable(string $name, callable $callable): void
    {
        $this->callables[$name] = $callable;
    }

    public function allow(string $name): void
    {
        $this->allowed[$name] = true;
    }

    public function isCallable(string $name): bool
    {
        return isset($this->callables[$name]);
    }

    public function isAllowed(string $name): bool
    {
        return isset($this->allowed[$name]);
    }

    /**
     * @return array<string, callable>
     */
    public function allCallables(): array
    {
        return $this->callables;
    }
}