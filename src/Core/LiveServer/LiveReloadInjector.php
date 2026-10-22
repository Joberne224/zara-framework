<?php

declare(strict_types=1);

namespace Zara\Core\LiveServer;

/**
 * Injecte le snippet JS de live reload juste avant </body>.
 *
 * Le JS se connecte au serveur SSE (port dédié) et réagit aux événements :
 *   - 'reload' + data 'css'  → recharge uniquement les <link rel="stylesheet">
 *   - 'reload' + data 'full' → recharge toute la page
 *   - 'ping'                 → keepalive ignoré côté JS
 */
final class LiveReloadInjector
{
    public function __construct(
        private readonly string $host,
        private readonly int    $ssePort,
    ) {}

    public function inject(string $html): string
    {
        if (!str_contains($html, '</body>')) {
            return $html;
        }

        return str_replace('</body>', $this->buildScript() . '</body>', $html);
    }

    private function buildScript(): string
    {
        $url = "http://{$this->host}:{$this->ssePort}";

        // JS minimaliste, sans dépendance. Aucun bundler requis.
        return <<<HTML
            <script>(function () {
                var es = new EventSource('{$url}');

                es.addEventListener('reload', function (e) {
                    if (e.data === 'css') {
                        // Rechargement CSS sans flash de page : on incrémente un paramètre
                        // de cache-busting sur chaque feuille de style.
                        document.querySelectorAll('link[rel="stylesheet"]').forEach(function (link) {
                            var u = new URL(link.href);
                            u.searchParams.set('_z', Date.now());
                            link.href = u.toString();
                        });
                    } else {
                        location.reload();
                    }
                });

                es.onerror = function () {
                    console.debug('[Zara] Live reload : reconnexion...');
              };

            console.debug('[Zara] Live reload connecté sur {$url}');
        }());</script>
        HTML;
    }
}