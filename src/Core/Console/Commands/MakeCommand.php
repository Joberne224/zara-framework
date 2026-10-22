<?php

declare(strict_types=1);

namespace Zara\Core\Console\Commands;

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zara\Core\Console\GenerationContext;
use Zara\Core\Console\StubFileGenerator;

abstract class MakeCommand extends Command
{
    public function __construct(protected readonly StubFileGenerator $fileGenerator)
    {
        parent::__construct(static::getCommandName());
    }

    /** Each subclass declares its own command name. */
    abstract protected static function getCommandName(): string;

    protected function configure(): void
    {
        $this->addArgument(
            'name',
            InputArgument::REQUIRED,
            'Name of the class to generate',
        );
    }

    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = (string) $input->getArgument('name');

        try {
            $context = $this->getGenerationContext($name);

            if ($this->supportsDeletion() && $input->getOption('delete')) {
                $this->fileGenerator->assertFileExists($context->relativeFilePath);

                if (!$input->isInteractive()) {
                    throw new RuntimeException('Controller deletion requires interactive confirmation.');
                }

                if (!$io->confirm("Delete {$context->relativeFilePath}?", false)) {
                    $io->note('Deletion cancelled.');
                    return Command::SUCCESS;
                }

                $this->fileGenerator->delete($context->relativeFilePath);
                $io->success("Deleted file: {$context->relativeFilePath}");
                return Command::SUCCESS;
            }

            $generatedFile = $this->fileGenerator->generate($context);
        } catch (RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success("Generated file: {$generatedFile->relativeFilePath}");
        return Command::SUCCESS;
    }

    abstract protected function getGenerationContext(string $name): GenerationContext;

    protected function supportsDeletion(): bool
    {
        return false;
    }

    /** Ensures the name ends with the requested suffix. */
    protected function ensureSuffix(string $name, string $suffix): string
    {
        return str_ends_with($name, $suffix) ? $name : "{$name}{$suffix}";
    }
}