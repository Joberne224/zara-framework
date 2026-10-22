<?php

declare(strict_types=1);

namespace Zara\Core\Routing\Loader;

use InvalidArgumentException;
use RuntimeException;

/**
 * Contract for route loaders handling different file formats.
 */
interface RouteLoaderInterface
{
    /**
     * Loads routes from a specified file into the Router.
     *
     * @param string $filePath Absolute path to the route configuration file.
     * @throws RuntimeException If reading or parsing fails.
     * @throws InvalidArgumentException If route structure is invalid.
     */
    public function load(string $filePath): void;

    /**
     * Checks if this loader supports the given file format based on extension.
     *
     * @param string $filePath File path to check.
     */
    public function supports(string $filePath): bool;
}