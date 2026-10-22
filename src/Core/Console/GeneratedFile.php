<?php

declare(strict_types=1);

namespace Zara\Core\Console;

final class GeneratedFile
{
    public function __construct(
        public readonly string $absoluteFilePath,
        public readonly string $relativeFilePath,
    ) {}
}