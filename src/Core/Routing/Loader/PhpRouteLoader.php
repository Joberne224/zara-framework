<?php

declare(strict_types=1);

namespace Zara\Core\Routing\Loader;

use RuntimeException;
use Zara\Core\Routing\Router;

/**
 * Loads PHP imperative route definition files.
 */
final class PhpRouteLoader implements RouteLoaderInterface
{
    public function __construct(private readonly Router $router) {}

    public function load(string $filePath): void
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new RuntimeException(
                sprintf("PHP route file '%s' does not exist or is not readable.", $filePath)
            );
        }

        $router = $this->router;
        require $filePath;
    }

    public function supports(string $filePath): bool
    {
        return strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'php';
    }
}