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

/**
 * Thin CLI wrapper: all logic lives in MigrationRunner
 * (Zara\Database\Migrations); this command only translates the result into
 * console output — same separation as RouteCacheCommand with Router::compile().
 */
#[AsCommand(name: 'migrate', description: 'Apply pending database migrations')]
final class MigrateCommand extends Command
{
    public function __construct(private readonly MigrationRunner $runner)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $applied = $this->runner->migrate();
        } catch (MigrationException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if ($applied === []) {
            $io->comment('No pending migrations.');
            return Command::SUCCESS;
        }

        foreach ($applied as $migration) {
            $io->writeln("  <fg=green>✔</> {$migration}");
        }

        $io->success(sprintf('%d migration(s) applied.', count($applied)));

        return Command::SUCCESS;
    }
}
