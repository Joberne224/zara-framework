<?php

declare(strict_types=1);

namespace Zara\Core\LiveServer;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Starts two processes in parallel:
 *   - Process 1: the app HTTP server (php -S host:appPort)
 *   - Process 2: the SSE server (php -S host:ssePort sse-entrypoint.php)
 *
 * The main process runs the FileWatcher loop, which writes to ChangeSignal
 * whenever a file changes.
 *
 * Both child processes are terminated cleanly on shutdown (Ctrl+C).
 */
#[AsCommand(name: 'serve:live', description: 'Launch the development server with live reload')]
final class LiveServerCommand extends Command
{
    private const DEFAULT_HOST     = 'localhost';
    private const DEFAULT_APP_PORT = '8000';
    private const DEFAULT_SSE_PORT = '8001';

    public function __construct(private readonly string $basePath)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('host',     null, InputOption::VALUE_OPTIONAL, 'Server host', self::DEFAULT_HOST)
            ->addOption('port',     'p',  InputOption::VALUE_OPTIONAL, 'Application server port', self::DEFAULT_APP_PORT)
            ->addOption('sse-port', null, InputOption::VALUE_OPTIONAL, 'SSE server port', self::DEFAULT_SSE_PORT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $host    = (string) $input->getOption('host');
        $appPort = (int)    $input->getOption('port');
        $ssePort = (int)    $input->getOption('sse-port');

        $config = WatchConfig::default($this->basePath, $host, $appPort, $ssePort);

        if (!is_dir($config->documentRoot)) {
            $io->error("Public directory not found: {$config->documentRoot}");
            return Command::FAILURE;
        }

        $signal = new ChangeSignal($config->signalPath);

        $appProcess = $this->startAppServer($config);
        $sseProcess = $this->startSseServer($config);

        // Cleanup is guaranteed even on Ctrl+C.
        $this->registerShutdown($appProcess, $sseProcess, $signal);

        $this->renderBanner($io, $config);

        // Main loop: blocks until the stop signal is received.
        (new FileWatcher(
            config:   $config,
            signal:   $signal,
            onChange: fn(string $file) => $io->writeln(
                sprintf('  <fg=yellow>~</> Changed: <fg=gray>%s</>', $this->relativize($file, $this->basePath))
            ),
        ))->watch();

        // Never reached, but required by the type analysis for watch(): never.
        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Processus
    // -------------------------------------------------------------------------

    /** @return resource */
    private function startAppServer(WatchConfig $config): mixed
    {
        $cmd = sprintf(
            'php -S %s -t %s',
            escapeshellarg("{$config->host}:{$config->appPort}"),
            escapeshellarg($config->documentRoot),
        );

        return $this->openProcess($cmd);
    }

    /** @return resource */
    private function startSseServer(WatchConfig $config): mixed
    {
        $autoload = $this->basePath . '/vendor/autoload.php';
    
        $cmd = sprintf(
            'php -S %s %s',
            escapeshellarg("{$config->host}:{$config->ssePort}"),
            escapeshellarg(SseHandler::entrypointPath()),
        );

        // Environment variables pass context to sse-entrypoint.php without
        // requiring CLI arguments.
        return $this->openProcess($cmd, [
            'ZARA_AUTOLOAD'    => $autoload,
            'ZARA_SIGNAL_PATH' => $config->signalPath,
        ]);
    }

    /**
     * @param array<string, string> $env
     * @return resource
     */
    private function openProcess(string $cmd, array $env = []): mixed
    {
        $descriptor = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout (ignored)
            2 => ['pipe', 'w'],  // stderr (ignored)
        ];

        // proc_open() replaces the inherited environment whenever $env is not
        // empty, instead of merging with it. The child process would otherwise
        // lose PATH, HOME, etc. We explicitly merge the current environment and
        // let $env win on key collisions.
        $process = proc_open($cmd, $descriptor, $pipes, null, $env ? $env + getenv() : null);

        if ($process === false) {
            throw new \RuntimeException("Unable to start process: {$cmd}");
        }

        // Close the unused pipes so they do not block the child process.
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return $process;
    }

    // -------------------------------------------------------------------------
    // Clean shutdown
    // -------------------------------------------------------------------------

    /**
     * @param resource $appProcess
     * @param resource $sseProcess
     */
    private function registerShutdown(mixed $appProcess, mixed $sseProcess, ChangeSignal $signal): void
    {
        register_shutdown_function(function () use ($appProcess, $sseProcess, $signal): void {
            $this->terminateProcess($appProcess);
            $this->terminateProcess($sseProcess);

            if (file_exists($signal->getPath())) {
                unlink($signal->getPath());
            }
        });
    }

    /** @param resource $process */
    private function terminateProcess(mixed $process): void
    {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }

    // -------------------------------------------------------------------------
    // UI
    // -------------------------------------------------------------------------

    private function renderBanner(SymfonyStyle $io, WatchConfig $config): void
    {
        $extensions = implode(', ', $config->extensions);

        $io->writeln('');
        $io->writeln('  <fg=white;options=bold>Zara Live Server</>');
        $io->writeln('  <fg=gray>────────────────────────────────</>');
        $io->writeln("  <fg=green>✔</> App    : <fg=cyan;options=bold>http://{$config->host}:{$config->appPort}</>");
        $io->writeln("  <fg=green>✔</> SSE    : <fg=cyan>http://{$config->host}:{$config->ssePort}</>");
        $io->writeln("  <fg=gray>  Root   : {$config->documentRoot}</>");
        $io->writeln("  <fg=gray>  Watch  : {$extensions}</>");
        $io->writeln('  <fg=gray>  Press Ctrl+C to stop.</>');
        $io->writeln('');
    }

    private function relativize(string $absolutePath, string $basePath): string
    {
        return ltrim(str_replace($basePath, '', $absolutePath), '/');
    }
}