<?php

declare(strict_types=1);

namespace Zara\Core\LiveServer;

/**
 * Value object de configuration du live server.
 * Immutable : construit une fois, partagé entre FileWatcher,
 * SseHandler et LiveServerCommand.
 */
final class WatchConfig
{
    /**
     * @param list<string> $watchedPaths Chemins absolus à surveiller
     * @param list<string> $extensions   Extensions sans le point (ex: 'css', 'php')
     */
    public function __construct(
        public readonly string $host,
        public readonly int    $appPort,
        public readonly int    $ssePort,
        public readonly string $documentRoot,
        public readonly array  $watchedPaths,
        public readonly array  $extensions,
        public readonly int    $pollIntervalMs,
        public readonly string $signalPath,
    ) {}

    public static function default(
        string $basePath,
        string $host    = 'localhost',
        int    $appPort = 8000,
        int    $ssePort = 8001,
    ): self {
        return new self(
            host:           $host,
            appPort:        $appPort,
            ssePort:        $ssePort,
            documentRoot:   $basePath . '/public',
            watchedPaths:   array_values(array_filter([
                $basePath . '/src',
                $basePath . '/resources',
                $basePath . '/public',
            ], 'is_dir')),
            extensions:     ['php', 'css', 'js', 'html', 'twig', 'svg'],
            pollIntervalMs: 300,
            // CORRECTION : Aligné sur la valeur de secours par défaut de sse-entrypoint.php.
            // Évite le dysfonctionnement si getenv('ZARA_SIGNAL_PATH') échoue dans le sous-processus.
            signalPath:     sys_get_temp_dir() . '/zara-reload.sig',
        );
    }
}