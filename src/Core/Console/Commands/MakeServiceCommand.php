<?php

declare(strict_types=1);

namespace Zara\Core\Console\Commands;

use Zara\Core\Console\GenerationContext;

final class MakeServiceCommand extends MakeCommand
{
    protected static function getCommandName(): string
    {
        return 'make:service';
    }

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Generate a new service');
    }

    protected function getGenerationContext(string $name): GenerationContext
    {
        $className = $this->ensureSuffix($name, 'Service');

        return new GenerationContext(
            className: $className,
            namespace: 'Zara\\Services',
            relativeFilePath: "src/Services/{$className}.php",
            stub: 'service',
        );
    }
}