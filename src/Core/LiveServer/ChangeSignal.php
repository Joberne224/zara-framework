<?php

declare(strict_types=1);

namespace Zara\Core\LiveServer;

/**
 * Fichier signal partagé entre FileWatcher (écrivain) et SseHandler (lecteur).
 *
 * Format : "{microtime}:{type}"  ex: "1717000000.123456:css"
 *
 * Le type 'css' déclenche un rechargement des feuilles de style uniquement.
 * Tout autre type déclenche un rechargement complet de la page.
 */
final class ChangeSignal
{
    public function __construct(private readonly string $path) {}

    public function touch(string $type = 'full'): void
    {
        file_put_contents($this->path, microtime(true) . ':' . $type, LOCK_EX);
    }

    public function read(): string
    {
        if (!file_exists($this->path)) {
            return '';
        }

        return file_get_contents($this->path) ?: '';
    }

    public function extractType(string $signal): string
    {
        return explode(':', $signal, 2)[1] ?? 'full';
    }

    public function getPath(): string
    {
        return $this->path;
    }
}