<?php
// file: Zara/Core/Http/Headers.php
declare(strict_types=1);

namespace Zara\Core\Http\Interfaces;

interface HeadersInterface
{
    public static function createFromGlobals(): self;

    public function get(string $name): ?string;

    public function getAll(string $name): array;
    public function has(string $name): bool;

    public function with(string $name, $value): self;

    public function without(string $name): self;

    public function toArray(): array;
}