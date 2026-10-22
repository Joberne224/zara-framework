<?php

declare(strict_types=1);

namespace Zara\Core\Console\Commands;

use Zara\Core\Console\GenerationContext;

final class MakeMiddlewareCommand extends MakeCommand
{
    protected static function getCommandName(): string
    {
        return 'make:middleware';
    }

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Generate a new middleware');
    }

    protected function getGenerationContext(string $name): GenerationContext
    {
        $className = $this->ensureSuffix($name, 'Middleware');

        return new GenerationContext(
            className: $className,
            namespace: 'Zara\\Middlewares',
            relativeFilePath: "src/Middleware/{$className}.php",
            stub: 'middleware',
        );
    }
}