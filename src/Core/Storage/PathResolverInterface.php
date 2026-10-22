<?php

declare(strict_types=1);

namespace Zara\Core\Storage;

interface PathResolverInterface
{
    public function resolve(string $path): string;

    public function root(): string;
}