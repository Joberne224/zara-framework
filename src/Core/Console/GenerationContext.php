<?php

declare(strict_types=1);

namespace Zara\Core\Console;

final class GenerationContext
{
    public function __construct(
        public readonly string $className,
        public readonly string $namespace,
        public readonly string $relativeFilePath,
        public readonly string $stub,
        public readonly array $replacements = [],
    ) {}
}