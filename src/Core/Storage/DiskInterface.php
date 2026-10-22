<?php
declare(strict_types=1);

namespace Zara\Core\Storage;

interface DiskInterface
{
    public function read(string $path): string;
    public function write(string $path, string $contents): void;
    public function delete(string $path): void;
    public function exists(string $path): bool;
    public function size(string $path): int;
    public function path(string $path): string; // chemin absolu résolu, sans I/O
}