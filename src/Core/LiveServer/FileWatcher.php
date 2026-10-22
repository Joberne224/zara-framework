<?php

declare(strict_types=1);

namespace Zara\Core\LiveServer;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Surveille les fichiers en comparant leurs mtime à chaque tick.
 * Lorsqu'un changement est détecté, met à jour le ChangeSignal
 * et appelle le callback $onChange (optionnel) avec le chemin du fichier modifié.
 *
 * Tourne dans le processus principal de LiveServerCommand,
 * en boucle bloquante — c'est intentionnel.
 */
final class FileWatcher
{
    /** @var array<string, int> mtime par chemin absolu, snapshot du tick précédent */
    private array $previousMtimes = [];

    /**
     * @param Closure(string): void|null $onChange  Appelé avec le chemin absolu du fichier modifié
     */
    public function __construct(
        private readonly WatchConfig  $config,
        private readonly ChangeSignal $signal,
        private readonly ?Closure     $onChange = null,
    ) {}

    public function watch(): never
    {
        while (true) {
            $this->tick();
            usleep($this->config->pollIntervalMs * 1000);
        }
    }

    private function tick(): void
    {
        clearstatcache(); // <-- CORRECTION : Force le rafraîchissement du cache des statuts de fichiers

        [$currentMtimes, $changedFile, $changedType] = $this->scan();

        $isFirstRun = empty($this->previousMtimes);

        if (!$isFirstRun && $currentMtimes !== $this->previousMtimes) {
            $this->signal->touch($changedType);
            if ($this->onChange !== null && $changedFile !== null) {
                ($this->onChange)($changedFile);
            }
        }

        $this->previousMtimes = $currentMtimes;
    }

    /**
     * @return array{array<string,int>, string|null, string}  [mtimes, changedFile, type]
     */
    private function scan(): array
    {
        $mtimes      = [];
        $changedFile = null;
        $changedType = 'full';

        foreach ($this->config->watchedPaths as $watchedPath) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($watchedPath, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $ext = strtolower($file->getExtension());

                if (!in_array($ext, $this->config->extensions, strict: true)) {
                    continue;
                }

                $pathname = $file->getPathname();
                $mtime    = $file->getMTime();

                // Premier fichier dont le mtime a changé → qualifie le type de reload
                if (
                    $changedFile === null
                    && isset($this->previousMtimes[$pathname])
                    && $this->previousMtimes[$pathname] !== $mtime
                ) {
                    $changedFile = $pathname;
                    $changedType = ($ext === 'css') ? 'css' : 'full';
                }

                $mtimes[$pathname] = $mtime;
            }
        }

        ksort($mtimes);

        return [$mtimes, $changedFile, $changedType];
    }
}