<?php

declare(strict_types=1);

namespace Zara\Core\Console\Commands;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zara\Core\Routing\Router;

#[AsCommand(name: 'route:cache', description: 'Compile routes into a cache file')]
final class RouteCacheCommand extends Command
{
    public function __construct(
        private readonly Router $router,
        private readonly string $routesFile,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!file_exists($this->routesFile)) {
            $io->error("Routes file not found: {$this->routesFile}");
            return Command::FAILURE;
        }

        try {
            $this->router->load($this->routesFile);
            $this->router->compile();
        } catch (RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success('Routes cache generated successfully.');
        return Command::SUCCESS;
    }
}