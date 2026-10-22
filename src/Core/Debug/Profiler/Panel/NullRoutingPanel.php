<?php

declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Panel;

use Zara\Core\Debug\Profiler\PanelInterface;
use Zara\Core\Debug\Profiler\Panel\Interfaces\RoutingPanelInterface;

/**
 * Remplaçant transparent de RoutingPanel quand le profiler est désactivé.
 * Implémente les deux contrats de RoutingPanel :
 *  - RoutingPanelInterface : alimentation des données (consommé par Kernel)
 *  - PanelInterface        : rendu (consommé par le profiler/debug bar)
 * pour pouvoir se substituer à RoutingPanel dans n'importe quel des deux
 * usages sans casser le typage ni exiger de cas particulier.
 */
final class NullRoutingPanel implements RoutingPanelInterface, PanelInterface
{
    public function setMatchedRoute(
        string $path,
        callable|array|string $callable,
        array $params,
        array $middlewares
    ): void {
        // Intentionnellement no-op : profiler désactivé.
    }

    public function getId(): string
    {
        return 'routing';
    }

    public function getLabel(): string
    {
        return 'Routing';
    }

    public function getIcon(): string
    {
        return '';
    }

    public function getSummary(): string
    {
        return '';
    }

    public function getBadgeColor(): string
    {
        return 'var(--zdb-ok)';
    }

    public function renderContent(): string
    {
        return '';
    }
}