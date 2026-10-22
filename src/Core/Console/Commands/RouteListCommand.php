<?php

declare(strict_types=1);

namespace Zara\Core\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zara\Core\Routing\Router;

/**
 * Displays the routes registered on the Router.
 *
 * It intentionally stays thin: all knowledge of the internal route structure
 * (callable, middlewares, path) is delegated to Router::getRoutes(); this
 * command only filters and renders the result. This mirrors the same
 * responsibility split as MakeControllerCommand/MakeCommand, where the command
 * builds/reads a context without knowing the underlying implementation details.
 */
#[AsCommand(name: 'route:list', description: 'List all registered routes')]
final class RouteListCommand extends Command
{
    public function __construct(private readonly Router $router)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'method',
            'm',
            InputOption::VALUE_OPTIONAL,
            'Filter by HTTP method (GET, POST, PUT, DELETE...)'
        );

        $this->addOption(
            'path',
            'p',
            InputOption::VALUE_OPTIONAL,
            'Filter by path (for example /users)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $routes = $this->filterRoutes(
            $this->router->getRoutes(),
            method: $input->getOption('method'),
            path: $input->getOption('path'),
        );

        if (empty($routes)) {
            $io->warning('No route found.');
            return Command::SUCCESS;
        }

        $io->table(
            ['Method', 'Path', 'Handler', 'Middleware'],
            array_map($this->toTableRow(...), $routes)
        );

        $io->text(sprintf('<fg=gray>%d route(s) found.</>', count($routes)));

        return Command::SUCCESS;
    }

    /**
     * @param list<array{method: string, path: string, handler: string, middlewares: list<string>}> $routes
     * @return list<array{method: string, path: string, handler: string, middlewares: list<string>}>
     */
    private function filterRoutes(array $routes, ?string $method, ?string $path): array
    {
        if ($method !== null && $method !== '') {
            $routes = array_filter(
                $routes,
                fn(array $route) => strtoupper($route['method']) === strtoupper($method)
            );
        }

        if ($path !== null && $path !== '') {
            $routes = array_filter(
                $routes,
                fn(array $route) => str_contains($route['path'], $path)
            );
        }

        return array_values($routes);
    }

    /**
     * @param array{method: string, path: string, handler: string, middlewares: list<string>} $route
     * @return list<string>
     */
    private function toTableRow(array $route): array
    {
        return [
            sprintf('<fg=%s>%s</>', $this->methodColor($route['method']), $route['method']),
            $route['path'],
            $route['handler'],
            implode(', ', $route['middlewares']) ?: '-',
        ];
    }

    private function methodColor(string $method): string
    {
        return match (strtoupper($method)) {
            'GET'    => 'green',
            'POST'   => 'yellow',
            'PUT',
            'PATCH'  => 'blue',
            'DELETE' => 'red',
            default  => 'white',
        };
    }
}