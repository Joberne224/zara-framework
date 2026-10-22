<?php

declare(strict_types=1);

namespace Zara\Core\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\SimpleCache\CacheInterface;
use Zara\Cache\Interfaces\CacheCompactionInterface;
use Zara\Cache\Interfaces\CacheMaintenanceInterface;
use Zara\Cache\ValidatingCache;
use Zara\Core\Debug\Profiler\Support\ProfiledCache;
use Zara\Core\Config\Env;

#[AsCommand(name: 'cache:cleanup', description: 'Inspect and clean up expired cache entries')]
final class CacheCleanupCommand extends Command
{
    public function __construct(private readonly CacheInterface $cache)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Delete expired entries')
            ->addOption('vacuum', null, InputOption::VALUE_NONE, 'Compact cache storage when supported');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!Env::isProfilerEnabled()) {
            $io->error('This command is restricted to development environments with debug mode enabled.');
            return Command::FAILURE;
        }

        if ($input->getOption('vacuum') && !$input->getOption('execute')) {
            $io->error('The --vacuum option also requires --execute.');
            return Command::FAILURE;
        }

        $backend = $this->cache;
        while ($backend instanceof ProfiledCache || $backend instanceof ValidatingCache) {
            $backend = $backend->getBackend();
        }

        if (!$backend instanceof CacheMaintenanceInterface) {
            $io->error(sprintf(
                'The %s cache backend does not support expired-entry maintenance.',
                (new \ReflectionClass($backend))->getShortName(),
            ));
            return Command::FAILURE;
        }
        if ($input->getOption('vacuum') && !$backend instanceof CacheCompactionInterface) {
            $io->error(sprintf(
                'The %s cache backend does not support storage compaction.',
                (new \ReflectionClass($backend))->getShortName(),
            ));
            return Command::FAILURE;
        }

        try {
            $expired = $backend->getExpiredEntryCount();
            $io->definitionList(
                ['Cache backend' => (new \ReflectionClass($backend))->getShortName()],
                ['Expired entries' => $expired === null ? 'Managed automatically by the backend' : (string) $expired],
            );

            if (!$input->getOption('execute')) {
                $io->comment('No changes made. Run again with --execute to clean up expired entries.');
                return Command::SUCCESS;
            }

            $deleted = $backend->cleanupExpired();
            if ($deleted === null) {
                $io->success('Expiration is managed automatically by the cache backend; no manual cleanup is needed.');
            } else {
                $io->success(sprintf('Expired cache entries deleted: %d.', $deleted));
            }

            if ($input->getOption('vacuum')) {
                $io->warning('Storage compaction may take time or temporarily lock the cache backend.');
                $backend->compact();
                $io->success('Cache storage compacted.');
            }
        } catch (\Throwable $exception) {
            $io->error('Cache cleanup failed: ' . $exception->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
