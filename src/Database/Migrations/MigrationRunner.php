<?php

declare(strict_types=1);

namespace Zara\Database\Migrations;

use Zara\Database\DatabaseInterface;
use Zara\Database\Migrations\Internal\MigrationClassResolver;
use Zara\Database\Migrations\Internal\MigrationExecutor;
use Zara\Database\Migrations\Internal\MigrationFileScanner;
use Zara\Database\Migrations\Internal\MigrationRepository;
use Zara\Database\Schema\Schema;

/**
 * Single entry point for the migration system. It is intentionally
 * independent of the CLI: MigrateCommand/MigrateRollbackCommand
 * (Zara\Core\Console\Commands) only call migrate()/rollback() and format
 * the result for console output. This class can be called from any context
 * (deployment script, test bootstrap).
 */
final class MigrationRunner
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly Schema $schema,
        private readonly MigrationFileScanner $scanner,
        private readonly MigrationClassResolver $resolver,
        private readonly MigrationRepository $repository,
        private readonly MigrationExecutor $executor,
    ) {
    }

    /**
     * @return list<string> Migrations applied during this call (empty if nothing was pending)
     */
    public function migrate(): array
    {
        $this->repository->ensureTableExists();

        $pending = array_values(array_diff(
            $this->scanner->scan(),
            $this->repository->getApplied(),
        ));

        if ($pending === []) {
            return [];
        }

        $batch = $this->repository->getNextBatch();
        $ranNow = [];

        foreach ($pending as $migrationName) {
            $migration = $this->resolver->resolve(
                $this->scanner->pathFor($migrationName),
                $migrationName,
            );

            $this->executor->up(
                $migration,
                $this->db,
                $this->schema,
                fn() => $this->repository->record($migrationName, $batch),
            );

            $ranNow[] = $migrationName;
        }

        return $ranNow;
    }

    /**
     * Rolls back the entire last applied batch, not just the last migration.
     *
     * @return list<string> Migrations rolled back (empty if there was nothing to roll back)
     */
    public function rollback(): array
    {
        $this->repository->ensureTableExists();

        $toRollback = $this->repository->getMigrationsForLastBatch();

        foreach ($toRollback as $migrationName) {
            $migration = $this->resolver->resolve(
                $this->scanner->pathFor($migrationName),
                $migrationName,
            );

            $this->executor->down(
                $migration,
                $this->db,
                $this->schema,
                fn() => $this->repository->remove($migrationName),
            );
        }

        return $toRollback;
    }
}
