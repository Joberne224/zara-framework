<?php

declare(strict_types=1);

namespace Zara\Core\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zara\Core\Routing\Exceptions\RequestMethodNotFoundException;
use Zara\Core\Routing\Exceptions\RouteNotFoundException;
use Zara\Core\Routing\RouteMatch;
use Zara\Core\Routing\Router;

/**
 * Debug helper: tells which route matches a given method and URI without
 * launching a real HTTP request.
 *
 * It uses Router::matchPath() instead of Router::match(Request), because the
 * match depends only on the method and URI; there is no need to build a full
 * HTTP request (cookies, session, files...) just to test it.
 */
#[AsCommand(name: 'route:match', description: 'Test which route matches a given method and URI')]
final class RouteMatchCommand extends Command
{
    public function __construct(private readonly Router $router)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('method', InputArgument::REQUIRED, 'HTTP method (GET, POST, PUT, DELETE...)');
        $this->addArgument('uri', InputArgument::REQUIRED, 'URI to test (for example /users/42)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $method = strtoupper((string) $input->getArgument('method'));
        $uri    = (string) $input->getArgument('uri');

        try {
            $match = $this->router->matchPath($method, $uri);
        } catch (RouteNotFoundException|RequestMethodNotFoundException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $this->renderMatch($io, $method, $uri, $match);

        return Command::SUCCESS;
    }

    private function renderMatch(SymfonyStyle $io, string $method, string $uri, RouteMatch $match): void
    {
        $io->success("{$method} {$uri} matches a route.");

        $rows = [
            ['Namespace', $match->getNamespace() ?: '-'],
        ];

        if ($match->hasController()) {
            $rows[] = ['Controller', $match->getClass()];
            $rows[] = ['Method', $match->getMethod()];
        } else {
            $callable = $match->getCallable();
            $rows[] = ['Callable', is_string($callable) ? $callable : '{closure}'];
        }

        $rows[] = ['Middlewares', implode(', ', $match->getMiddlewares()) ?: '-'];

        $io->table(['Champ', 'Valeur'], $rows);

        $params = $match->getParams();

        if (!empty($params)) {
            $io->table(
                ['Parameter', 'Value'],
                array_map(fn(string $key, string $value) => [$key, $value], array_keys($params), $params)
            );
        }
    }
}