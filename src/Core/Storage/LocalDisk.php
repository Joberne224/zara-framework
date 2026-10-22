<?php
declare(strict_types=1);

namespace Zara\Core\Storage;

use Zara\Core\Storage\Exceptions\FileNotFoundException;
use Zara\Core\Storage\Exceptions\UnwritableException;

final class LocalDisk implements DiskInterface
{
    public function __construct(private readonly PathResolver $resolver) {}

    public function read(string $path): string
    {
        $real = $this->resolver->resolve($path);
        if (!is_file($real)) {
            throw new FileNotFoundException($path);
        }
        $contents = @file_get_contents($real);
        if ($contents === false) {
            throw new UnwritableException($path);
        }
        return $contents;
    }

    public function write(string $path, string $contents): void
    {
        $real = $this->resolver->resolve($path);
        $dir = dirname($real);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new UnwritableException($path);
        }

        $tmp = $dir . DIRECTORY_SEPARATOR . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new UnwritableException($path);
        }
        chmod($tmp, 0644);

        if (!rename($tmp, $real)) {
            @unlink($tmp);
            throw new UnwritableException($path);
        }
    }

    public function delete(string $path): void
    {
        $real = $this->resolver->resolve($path);
        if (!is_file($real)) {
            throw new FileNotFoundException($path);
        }
        if (!unlink($real)) {
            throw new UnwritableException($path);
        }
    }

    public function exists(string $path): bool
    {
        try {
            return is_file($this->resolver->resolve($path));
        } catch (\Exception) {
            return false;
        }
    }

    public function size(string $path): int
    {
        $real = $this->resolver->resolve($path);
        if (!is_file($real)) {
            throw new FileNotFoundException($path);
        }
        return filesize($real);
    }

    public function path(string $path): string
    {
        return $this->resolver->resolve($path);
    }
}