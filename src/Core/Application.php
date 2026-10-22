<?php

declare(strict_types=1);

namespace Zara\Core;

use Symfony\Component\Console\Application as ConsoleApplication;
use Zara\Container\Container;
use Zara\Container\Compiler\Console\ContainerCompileCommand;
use Zara\Container\Compiler\Console\ContainerClearCommand;
use Zara\Container\Compiler\CompiledContainerWriter;
use Zara\Core\Console\StubFileGenerator;
use Zara\Core\Console\StubResolver;
use Zara\Core\LiveServer\LiveServerCommand;
use Zara\Core\Routing\Router;
use Zara\Database\DatabaseInterface;
use Zara\Database\Migrations\Internal\MigrationClassResolver;
use Zara\Database\Migrations\Internal\MigrationExecutor;
use Zara\Database\Migrations\Internal\MigrationFileScanner;
use Zara\Database\Migrations\Internal\MigrationRepository;
use Zara\Database\Migrations\MigrationRunner;
use Zara\Database\Schema\Schema;
use Psr\SimpleCache\CacheInterface;
use Zara\Core\Console\Commands\{
    AboutCommand,
    CacheCleanupCommand,
    ServeCommand,
    MakeControllerCommand,
    MakeMiddlewareCommand,
    MakeMigrationCommand,
    MakeServiceCommand,
    MigrateCommand,
    MigrateRollbackCommand,
    RouteListCommand,
    RouteCacheCommand,
    RouteClearCommand,
};

final class Application extends ConsoleApplication
{
    private readonly StubResolver $stubResolver;
    private readonly StubFileGenerator $fileGenerator;

    public function __construct(
        private readonly string $basePath,
        private readonly ?Router $router = null,
        private readonly ?string $routesFile = null,
        private readonly string $appRootNamespace = 'App',
        private readonly ?DatabaseInterface $database = null,
        private readonly ?Schema $schema = null,
        // Optionnel, même principe que $database/$schema : une CLI qui n'a
        // pas de Container assemblé n'a simplement pas container:compile,
        // exactement comme l'absence de Router prive l'app de route:*.
        private readonly ?Container $container = null,
        private readonly ?string $servicesYamlPath = null,
        private readonly ?string $compiledContainerPath = null,
        private readonly ?CacheInterface $cache = null,
    ) {
        parent::__construct('Zara', '1.0.0');

        $this->stubResolver = new StubResolver(
            appStubsPath: $this->basePath . '/stubs',
            frameworkStubsPath: dirname(__DIR__) . '/stubs',
            appRootNamespace: $this->appRootNamespace,
        );

        $this->fileGenerator = new StubFileGenerator($this->basePath, $this->stubResolver);

        $this->registerCommands();
    }

    private function registerCommands(): void
    {
        $this->addCommands([
            new ServeCommand($this->basePath),
            new LiveServerCommand($this->basePath),
            ...$this->makeMakeCommands(),
            ...$this->makeRouteCommands(),
            ...$this->makeMigrationCommands(),
            ...$this->makeContainerCommands(),
            ...$this->makeCacheCommands(),
        ]);
    }

    private function makeCacheCommands(): array
    {
        if ($this->cache === null) {
            return [];
        }

        return [new CacheCleanupCommand($this->cache)];
    }

    private function makeMakeCommands(): array
    {
        return [
            new MakeControllerCommand($this->fileGenerator),
            new MakeMiddlewareCommand($this->fileGenerator),
            new MakeServiceCommand($this->fileGenerator),
            new MakeMigrationCommand($this->fileGenerator),
            new AboutCommand($this->basePath),
        ];
    }

    private function makeRouteCommands(): array
    {
        if ($this->router === null) {
            return [];
        }

        $commands = [
            new RouteListCommand($this->router),
            new RouteClearCommand($this->router),
        ];

        if ($this->routesFile !== null) {
            $commands[] = new RouteCacheCommand($this->router, $this->routesFile);
        }

        return $commands;
    }

    private function makeMigrationCommands(): array
    {
        if ($this->database === null || $this->schema === null) {
            return [];
        }

        // Même source que StubResolver::getAppRootNamespace() : les
        // migrations générées par MakeMigrationCommand et la classe que
        // MigrationClassResolver va chercher à l'exécution doivent
        // s'accorder sur le même namespace racine, sans le dupliquer.
        $migrationNamespace = $this->stubResolver->getAppRootNamespace() . '\\Database\\Migrations';
        $migrationsPath = rtrim($this->basePath, '/') . '/database/migrations';

        $runner = new MigrationRunner(
            db: $this->database,
            schema: $this->schema,
            scanner: new MigrationFileScanner($migrationsPath),
            resolver: new MigrationClassResolver($migrationNamespace, $migrationsPath),
            repository: new MigrationRepository($this->database, $this->schema),
            executor: new MigrationExecutor(),
        );

        return [
            new MigrateCommand($runner),
            new MigrateRollbackCommand($runner),
        ];
    }

    private function makeContainerCommands(): array
    {
        if ($this->container === null || $this->servicesYamlPath === null || $this->compiledContainerPath === null) {
            return [];
        }

        $writer = new CompiledContainerWriter();

        return [
            new ContainerCompileCommand(
                $this->container,
                $this->servicesYamlPath,
                $this->compiledContainerPath,
            ),
            new ContainerClearCommand(
                $writer,
                $this->compiledContainerPath,
            ),
        ];
    }
}
