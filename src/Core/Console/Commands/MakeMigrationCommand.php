<?php

declare(strict_types=1);

namespace Zara\Core\Console\Commands;

use Symfony\Component\Console\Input\InputArgument;
use Zara\Core\Console\GenerationContext;
use Zara\Database\Migrations\Internal\MigrationNameResolver;

/**
 * Generates the skeleton of a new migration file.
 *
 * This differs from the other Make*Command classes in two ways mandated by the
 * nature of migrations rather than by arbitrary design choices:
 * - the file name includes a timestamp (Y_m_d_His), so the execution order can
 *   be inferred from alphabetical sorting instead of maintaining a separate
 *   registry;
 * - the file lives in database/migrations/ at the project root, outside src/:
 *   these files are not meant to be autoloaded via PSR-4; they are loaded by
 *   path by MigrationRunner (Zara\Database\Migrations).
 *
 * configure() therefore does not call MakeCommand::configure(): the 'name'
 * argument description does not mean the same thing here (it describes an
 * action, not a class name), and Symfony Console does not allow a previously
 * added argument to be re-declared with only its description changed.
 *
 * FIX: the namespace was previously hardcoded to 'Database\Migrations', which
 * placed application migrations under a namespace that looked like the
 * framework's own namespace (Zara\Database\Migrations). It now uses
 * getAppRootNamespace(), just like MakeControllerCommand/MakeServiceCommand, so
 * migrations remain application code instead of framework code.
 * The case conversion (snake_case / PascalCase) is delegated to
 * MigrationNameResolver, the single shared source of truth with
 * MigrationClassResolver, so the two can no longer drift apart.
 */
final class MakeMigrationCommand extends MakeCommand
{
    protected static function getCommandName(): string
    {
        return 'make:migration';
    }

    protected function configure(): void
    {
        $this->setDescription('Generate a new migration');
        $this->addArgument(
            'name',
            InputArgument::REQUIRED,
            'Migration action, for example create_users_table '
                . '(any casing is accepted: CreateUsersTable, create-users-table... are normalized the same way)',
        );
    }

    protected function getGenerationContext(string $name): GenerationContext
    {
        $snakeName = MigrationNameResolver::toSnakeCase($name);
        $className = MigrationNameResolver::toPascalCase($snakeName);
        $fileName  = date('Y_m_d_His') . "_{$snakeName}.php";

        return new GenerationContext(
            className: $className,
            namespace: $this->fileGenerator->getAppRootNamespace() . '\\Database\\Migrations',
            relativeFilePath: "database/migrations/{$fileName}",
            stub: 'migration.plain',
        );
    }
}
