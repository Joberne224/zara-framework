<?php

declare(strict_types=1);

namespace Zara\Core\Console\Commands;

use RuntimeException;
use Symfony\Component\Console\Input\InputOption;
use Zara\Core\Console\GenerationContext;

final class MakeControllerCommand extends MakeCommand
{
    protected static function getCommandName(): string
    {
        return 'make:controller';
    }

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Generate a new controller');
        $this->addOption(
            'delete',
            null,
            InputOption::VALUE_NONE,
            'Delete the named controller instead of generating it',
        );
    }

    protected function getGenerationContext(string $name): GenerationContext
    {
        $className = $this->ensureSuffix($name, 'Controller');
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $className) !== 1) {
            throw new RuntimeException('Controller names must be valid PHP class names.');
        }

        return new GenerationContext(
            className: $className,
            namespace: $this->fileGenerator->getAppRootNamespace() . '\\Controller',
            relativeFilePath: $this->fileGenerator->getAppSourcePath() . "/Controller/{$className}.php",
            stub: 'controller.plain',
        );
    }

    protected function supportsDeletion(): bool
    {
        return true;
    }
}