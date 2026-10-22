<?php

declare(strict_types=1);

namespace Zara\Core\Console\Commands;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zara\Core\Routing\Interfaces\RouterInterface;

#[AsCommand(name: 'route:clear', description: 'Clear the routes cache')]
final class RouteClearCommand extends Command
{
    public function __construct(private readonly RouterInterface $router)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $this->router->clear();
        } catch (RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success('Routes cache cleared.');
        return Command::SUCCESS;
    }
}