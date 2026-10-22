<?php

declare(strict_types=1);

namespace Zara\Core\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'serve', description: 'Launch the development server')]
final class ServeCommand extends Command
{
    private const DEFAULT_HOST = 'localhost';
    private const DEFAULT_PORT = '8000';

    public function __construct(private readonly string $basePath)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('host', null, InputOption::VALUE_OPTIONAL, 'Server host', self::DEFAULT_HOST);
        $this->addOption('port', 'p', InputOption::VALUE_OPTIONAL, 'Server port', self::DEFAULT_PORT);
        $this->addOption('open', 'o', InputOption::VALUE_NONE, 'Open the home page in the browser when the server starts');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {   
        $io   = new SymfonyStyle($input, $output);
        $host = (string) $input->getOption('host');
        $port = (string) $input->getOption('port');
        $root = $this->basePath . '/public';

        if (!is_dir($root)) {
            $io->error("Public folder not found : {$root}");
            return Command::FAILURE;
        }

        if ($this->isPortInUse($host, $port)) {
            $io->error(sprintf(
                "Port %s seems to be already in use on %s.\nAnother server may already be running  - to find : %s",
                $port,
                $host,
                $this->portLookupHint($port),
            ));
            return Command::FAILURE;
        }

        $routerContent = sprintf(
            '<?php
            $publicDir = %s;
            $uri = urldecode(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH));
            if ($uri !== "/" && file_exists($publicDir . $uri)) {
                if (is_file($publicDir . $uri)) {
                    return false;
                }
            }
            $_SERVER["REQUEST_NAME"] = "/index.php";
            $_SERVER["SCRIPT_NAME"] = "/index.php";
            require_once $publicDir . "/index.php";',
            var_export($root, true)
        );

        $tmpRouter = sys_get_temp_dir() . '/zara_router_' . uniqid() . '.php';
        file_put_contents($tmpRouter, $routerContent);

        register_shutdown_function(static function () use ($tmpRouter) {
            if (file_exists($tmpRouter)) {
                @unlink($tmpRouter);
            }
        });

        if (function_exists('pcntl_signal')) {
            declare(ticks = 1);
            $cleanUp = static function () use ($tmpRouter) {
                if (file_exists($tmpRouter)) {
                    @unlink($tmpRouter);
                }
                exit(0);
            };
            pcntl_signal(SIGINT, $cleanUp);
            pcntl_signal(SIGTERM, $cleanUp);
        }

        $this->renderBanner($io, $host, $port, $root);

        if ($input->getOption('open')) {
            $this->openBrowser($io, "http://{$host}:{$port}");
        }

        passthru(sprintf(
            '%s -S %s -t %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg("{$host}:{$port}"),
            escapeshellarg($root),
            escapeshellarg($tmpRouter)
        ));

        return Command::SUCCESS;
    }

      /**
     * Checks the port before launching php -S so we can fail with a clear
     * message instead of letting passthru() fail silently if another server
     * instance is already bound to the port.
     */
    private function isPortInUse(string $host, string $port): bool
    {
        // 0.0.0.0 is not a client-connectable address on all systems, so we
        // probe localhost instead. This is still valid because a server bound to
        // 0.0.0.0 also accepts connections on 127.0.0.1.
        $probeHost = $host === '0.0.0.0' ? '127.0.0.1' : $host;

        $connection = @fsockopen($probeHost, (int) $port, $errorCode, $errorMessage, 0.3);

        if ($connection === false) {
            return false;
        }

        fclose($connection);

        return true;
    }

    private function portLookupHint(string $port): string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => "netstat -ano | findstr :{$port}",
            default   => "lsof -i :{$port}",
        };
    }

    private function renderBanner(SymfonyStyle $io, string $host, string $port, string $root): void
    {
        $io->writeln('');
        $io->writeln('  <fg=white;options=bold>Zara Development Server</>');
        $io->writeln('  <fg=gray>────────────────────────────</>');
        $io->writeln("  <fg=green>✔</> Local : <fg=cyan;options=bold>http://{$host}:{$port}</>");
        $io->writeln("  <fg=gray>  Root  : {$root}</>");
        $io->writeln('  <fg=gray>  Press Ctrl+C to stop.</>');
        $io->writeln('');
    }

    /**
     * Opens $url in the system browser in the background after a short delay:
     * the server is started immediately afterwards with passthru(), so the
     * browser request needs to wait until the server is ready.
     */
    private function openBrowser(SymfonyStyle $io, string $url): void
    {
        $command = match (PHP_OS_FAMILY) {
            'Darwin'  => sprintf('(sleep 1; open %s) > /dev/null 2>&1 &', escapeshellarg($url)),
            'Windows' => sprintf('start /B cmd /C "timeout /t 1 >nul & start "" %s"', escapeshellarg($url)),
            // Linux and other Unix systems: xdg-open is the standard command.
            default   => sprintf('(sleep 1; xdg-open %s) > /dev/null 2>&1 &', escapeshellarg($url)),
        };

        exec($command, result_code: $resultCode);

        if ($resultCode !== 0) {
            $io->note("Unable to open the browser automatically. Please open it manually: {$url}");
        }
    }
}