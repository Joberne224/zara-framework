<?php

declare(strict_types=1);

namespace Zara\Core\Error\Renderer;

use Zara\Core\Error\HttpException\HttpException;

/**
 * Renderer JSON pour les API et requêtes AJAX/Fetch.
 * * En mode debug, il structure l'intégralité de la trace pour une lecture facile.
 * En production, il occulte les détails sensibles et renvoie un payload sécurisé.
 */
final class JsonRenderer implements RendererInterface
{
    public function __construct(private readonly bool $debug = false) {}

    public function render(\Throwable $e): string
    {
        $code = $e instanceof HttpException ? $e->getStatusCode() : 500;

        if (!$this->debug) {
            return (string) json_encode([
                'error' => [
                    'code'    => $code,
                    'message' => $code >= 500 ? 'Internal Server Error' : $e->getMessage()
                ]
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        // Mode Développement : Payload JSON ultra complet pour le front-end
        $trace = array_map(function ($frame) {
            return [
                'file'     => $frame['file'] ?? '[internal]',
                'line'     => $frame['line'] ?? null,
                'function' => ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '{closure}')
            ];
        }, $e->getTrace());

        return (string) json_encode([
            'error' => [
                'class'   => get_class($e),
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $trace
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}