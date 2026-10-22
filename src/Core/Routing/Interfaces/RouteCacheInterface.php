<?php

declare(strict_types=1);

namespace Zara\Core\Routing\Interfaces;

/**
 * Contract for route caching operations on storage disking systems.
 */
interface RouteCacheInterface
{
    public function exists(): bool;

    public function write(array $routes): void;

    public function read(): array;

    public function clear(): void;

    public function getPath(): string;
}