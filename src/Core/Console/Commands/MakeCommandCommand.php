<?php

declare(strict_types=1);

namespace Zara\Core\Console\Commands;

use Zara\Core\Console\GenerationContext;

/**
 * Generates the skeleton of a new application console command.
 *
 * The CLI name (for example app:send-emails) is derived automatically from the
 * provided name instead of being requested separately, keeping the command as
 * simple as MakeControllerCommand without extra options. The developer can
 * still adjust it manually in the generated file if the inferred result is not
 * suitable.
 */
final class MakeCommandCommand extends MakeCommand
{
    protected static function getCommandName(): string
    {
        return 'make:command';
    }

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Generate a new console command');
    }

    protected function getGenerationContext(string $name): GenerationContext
    {
        $className = $this->ensureSuffix($name, 'Command');

        return new GenerationContext(
            className: $className,
            namespace: $this->fileGenerator->getAppRootNamespace() . '\\Console\\Commands',
            relativeFilePath: "src/Console/Commands/{$className}.php",
            stub: 'command.plain',
            replacements: [
                '{{ command_name }}' => $this->deriveSignature($name),
            ],
        );
    }

    /**
     * Derives a kebab-case CLI name prefixed with 'app:' from the provided class
     * name. Example: 'SendEmails' -> 'app:send-emails'.
     */
    private function deriveSignature(string $name): string
    {
        $base = str_ends_with($name, 'Command') ? substr($name, 0, -strlen('Command')) : $name;

        $kebab = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $base));
        $kebab = str_replace(['_', ' '], '-', $kebab);
        $kebab = (string) preg_replace('/-+/', '-', $kebab);

        return 'app:' . trim($kebab, '-');
    }
}