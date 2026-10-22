<?php

declare(strict_types=1);

namespace Zara\Core\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zara\Core\Routing\Router;

/**
 * Overview of the Zara environment: framework, PHP, paths, and routing state.
 * Intentionally read-only: no action, just a quick overview to diagnose an
 * installation quickly.
 */
#[AsCommand(name: 'about', description: "Display information about the Zara environment")]
final class AboutCommand extends Command
{
    public function __construct(
        private readonly string $basePath,
        private readonly ?Router $router = null,
        private readonly ?string $routesFile = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->definitionList(
            'Environment',
            ['Framework'      => sprintf(
                '%s v%s',
                $this->getApplication()?->getName() ?? 'Zara',
                $this->getApplication()?->getVersion() ?? 'unknown',
            )],
            ['PHP'            => PHP_VERSION],
            ['OS'             => PHP_OS_FAMILY],
            ['Base path'      => $this->basePath],
            ['Public directory' => is_dir($this->basePath . '/public')
                ? '<fg=green>present</>'
                : '<fg=red>missing</>'],
            new TableSeparator(),
            'Routing',
            ...$this->routingRows(),
        );

        return Command::SUCCESS;
    }

    /**
     * @return list<array<string, string>>
     */
    private function routingRows(): array
    {
        if ($this->router === null) {
            return [
                ['Configured' => '<fg=yellow>no</> — no Router injected in bin/console'],
            ];
        }

        $rows = [
            ['Configured'        => '<fg=green>yes</>'],
            ['Loaded routes'     => (string) count($this->router->getRoutes())],
            ['Routes file'       => $this->routesFile ?? '-'],
        ];

        $rows[] = ['Cache' => match (true) {
            !$this->router->hasCache() => '<fg=gray>disabled</>',
            $this->router->isCached()  => '<fg=green>active</> — ' . $this->router->getCachePath(),
            default                    => '<fg=yellow>configured but empty</> — run route:cache',
        }];

        return $rows;
    }
}