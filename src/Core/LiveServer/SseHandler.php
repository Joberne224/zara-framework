<?php

declare(strict_types=1);

namespace Zara\Core\LiveServer;

/**
 * Gère la connexion Server-Sent Events côté serveur.
 *
 * Utilisé comme router script de `php -S` (via sse-entrypoint.php).
 * Toutes les requêtes arrivant sur le port SSE passent ici et reçoivent
 * un flux d'événements text/event-stream.
 */
final class SseHandler
{
    private const POLL_INTERVAL_US    = 200_000; // 200 ms
    private const KEEPALIVE_INTERVAL  = 15;      // secondes
    private const CONNECTION_TIMEOUT  = 300;     // 5 minutes, force reconnexion JS

    public function __construct(private readonly ChangeSignal $signal) {}

    /**
     * Chemin absolu vers l'entrypoint utilisé par LiveServerCommand
     * pour démarrer le serveur SSE via proc_open.
     */
    public static function entrypointPath(): string
    {
        return __DIR__ . '/sse-entrypoint.php';
    }

    public function handle(): never
    {
        $this->sendHeaders();

        $last      = $this->signal->read();
        $startTime = time();
        $lastPing  = time();

        $this->emit('ping', 'connected');

        while (true) {
            if (connection_aborted()) {
                exit;
            }

            if (time() - $startTime >= self::CONNECTION_TIMEOUT) {
                // Forcer la reconnexion côté JS pour éviter les connexions zombies
                $this->emit('ping', 'timeout');
                exit;
            }

            // CRUCIAL : Efface le cache de statuts de fichiers de PHP.
            // Sans cela, file_exists() et file_get_contents() renvoient en boucle
            // le même résultat mémorisé au premier tour.
            clearstatcache();

            $current = $this->signal->read();

            if ($current !== $last) {
                $this->emit('reload', $this->signal->extractType($current));
                $last = $current;
            }

            if (time() - $lastPing >= self::KEEPALIVE_INTERVAL) {
                $this->emit('ping', 'alive');
                $lastPing = time();
            }

            usleep(self::POLL_INTERVAL_US);
        }
    }

    private function sendHeaders(): void
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        // Nécessaire car le serveur SSE tourne sur un port différent de l'app
        header('Access-Control-Allow-Origin: *');

        if (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    private function emit(string $event, string $data): void
    {
        echo "event: {$event}\n";
        echo "data: {$data}\n\n";
        
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }
}