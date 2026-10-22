<?php

declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Panel;

use Zara\Core\Debug\Profiler\Panel\Interfaces\RoutingPanelInterface;
use Zara\Core\Debug\Profiler\PanelInterface;
use Zara\Core\Http\Request;
use Zara\Core\Http\Response;
use Zara\Core\Config\Env;

class RoutingPanel implements RoutingPanelInterface, PanelInterface
{
    /**
     * Holds the data of the route captured during the request cycle.
     */
    private array $routeInfo = [];

    /**
     * Fulfils the strict contract of PanelInterface.
     * For this panel the data is already pushed by the Kernel,
     * but the method must still be present and implemented.
     */
    public function collect(Request $request, Response $response): void
    {
        // Nothing to do here at the end of the cycle, setMatchedRoute() already did the work
    }

    /**
     * Lets the Kernel inject the routing information directly
     * as soon as the Router has validated a match.
     */
    public function setMatchedRoute(string $path, callable|array|string $callable, array $params, array $middlewares): void
    {
        if (!Env::isProfilerEnabled()) {
            return;
        }

        $this->routeInfo = [
            'path' => $path,
            'action' => $this->describeCallable($callable),
            'params' => $params,
            'middlewares' => $middlewares,
        ];
    }

    /**
     * Unique panel identifier (used as a CSS id).
     */
    public function getId(): string
    {
        return 'routing';
    }

    /**
     * Label displayed in the tab.
     */
    public function getLabel(): string
    {
        return 'Routing';
    }

    /**
     * SVG icon or emoji displayed in the tab.
     */
    public function getIcon(): string
    {
        return '🛣️';
    }

    /**
     * Summary value displayed directly in the tab bar.
     * E.g. 'HomeController@index' or '404 Not Found'
     */
    public function getSummary(): string
    {
        return $this->routeInfo['action'] ?? '404 Not Found';
    }

    /**
     * CSS color of the summary badge depending on the state.
     * Red alert (var(--zdb-err)) on a 404, standard color otherwise.
     */
    public function getBadgeColor(): string
    {
        return empty($this->routeInfo) ? 'var(--zdb-err)' : 'var(--zdb-ok)';
    }

    /**
     * HTML content of the panel (displayed when the tab is open).
     */
    public function renderContent(): string
    {
        $html = '<h3 style="margin-top:0;color:#6d4aff;">Zara Routing Information</h3>';
        
        if (empty($this->routeInfo)) {
            return $html . '<p style="color:#718096; font-style:italic;">No route was captured for this request (404 error).</p>';
        }

        $html .= '<table style="width:100%; border-collapse:collapse; text-align:left; font-size:13px;">';
        
        // 1. Route path
        $html .= sprintf(
            '<tr style="border-bottom:1px solid #1a1a24;"><td style="padding:8px; color:#718096; width:200px;">Route Pattern</td><td style="padding:8px; color:#fff; font-family:monospace;">/%s</td></tr>', 
            htmlspecialchars($this->routeInfo['path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
        
        // 2. Action / Target (Callable)
        $html .= sprintf(
            '<tr style="border-bottom:1px solid #1a1a24;"><td style="padding:8px; color:#718096;">Action / Target</td><td style="padding:8px; color:#34c759; font-weight:bold; font-family:monospace;">%s</td></tr>', 
            htmlspecialchars($this->routeInfo['action'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
        
        // 3. URL variables / parameters
        $paramsJson = json_encode(
            $this->routeInfo['params'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        $html .= sprintf(
            '<tr style="border-bottom:1px solid #1a1a24;"><td style="padding:8px; color:#718096; vertical-align:top;">URL Parameters ({id}, etc.)</td><td style="padding:8px; color:#fff; font-family:monospace; white-space:pre; background:#111116; border-radius:4px;">%s</td></tr>', 
            htmlspecialchars($paramsJson === false ? '[parameters unavailable]' : $paramsJson, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
        
        // 4. List of applied middlewares
        $html .= '<tr style="border-bottom:1px solid #1a1a24;"><td style="padding:8px; color:#718096; vertical-align:top;">Applied Middlewares</td><td style="padding:8px; color:#fff; font-family:monospace;">';
        
        if (empty($this->routeInfo['middlewares'])) {
            $html .= '<span style="color:#718096; font-style:italic;">No middleware active on this route</span>';
        } else {
            $html .= '<ul style="margin:0; padding-left:15px; color:#cbd5e1;">';
            foreach ($this->routeInfo['middlewares'] as $middleware) {
                $middlewareName = is_string($middleware) ? $middleware : get_debug_type($middleware);
                $html .= sprintf(
                    '<li style="margin-bottom:4px;">%s</li>',
                    htmlspecialchars($middlewareName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                );
            }
            $html .= '</ul>';
        }
        
        $html .= '</td></tr>';
        $html .= '</table>';

        return $html;
    }

    private function describeCallable(callable|array|string $callable): string
    {
        if (is_string($callable)) {
            return $callable;
        }
        if ($callable instanceof \Closure) {
            return 'Closure/Anonymous';
        }
        if (is_array($callable) && count($callable) === 2) {
            $target = is_object($callable[0]) ? $callable[0]::class : (string) $callable[0];
            return $target . '@' . (string) $callable[1];
        }

        return get_debug_type($callable);
    }
}
