<?php

declare(strict_types=1);

namespace Zara\Core\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zara\Database\Migrations\Exception\MigrationException;
use Zara\Database\Migrations\MigrationRunner;

#[AsCommand(name: 'migrate:rollback', description: 'Rollback the last batch of applied migrations')]
final class MigrateRollbackCommand extends Command
{
    public function __construct(private readonly MigrationRunner $runner)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $rolledBack = $this->runner->rollback();
        } catch (MigrationException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if ($rolledBack === []) {
            $io->comment('No migrations to rollback.');
            return Command::SUCCESS;
        }

        foreach ($rolledBack as $migration) {
            $io->writeln("  <fg=yellow>↩</> {$migration}");
        }

        $io->success(sprintf('%d migration(s) rolled back.', count($rolledBack)));

        return Command::SUCCESS;
    }
}
