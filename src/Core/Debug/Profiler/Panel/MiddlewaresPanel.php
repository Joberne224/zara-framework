<?php

declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Panel;

use Zara\Core\Debug\Profiler\PanelInterface;
use Zara\Core\Debug\Profiler\Support\MiddlewareTracker;

final class MiddlewaresPanel implements PanelInterface
{
    private const WARN_COUNT = 15;
    private const ERROR_COUNT = 30;

    public function getId(): string
    {
        return 'middlewares';
    }

    public function getLabel(): string
    {
        return 'Middlewares';
    }

    public function getIcon(): string
    {
        return '🧵';
    }

    public function getSummary(): string
    {
        $tracker = MiddlewareTracker::current();
        $count = count($tracker->getRecords());
        $totalMs = $tracker->getTotalDurationMs();

        return sprintf('%d middleware%s (%.1f ms)', $count, $count > 1 ? 's' : '', $totalMs);
    }

    public function getBadgeColor(): string
    {
        $records = MiddlewareTracker::current()->getRecords();

        foreach ($records as $record) {
            if ($record->failed) {
                return 'var(--zdb-err)';
            }
        }

        $count = count($records);

        return match (true) {
            $count > self::ERROR_COUNT => 'var(--zdb-err)',
            $count > self::WARN_COUNT => 'var(--zdb-warn)',
            default => 'var(--zdb-ok)',
        };
    }

    public function renderContent(): string
    {
        $tracker = MiddlewareTracker::current();
        $records = $tracker->getRecords();

        if ($records === []) {
            return '<h3 style="margin-top:0;color:#6d4aff;">Middlewares</h3>
                    <p style="color:#718096; font-style:italic;">No pipeline executed on this request.</p>';
        }

        $count = count($records);
        $totalMs = $tracker->getTotalDurationMs();

        $html = sprintf('<h3 style="margin-top:0;color:#6d4aff;">Middlewares (%d — %.1f ms total)</h3>', $count, $totalMs);

        $html .= '<p style="margin:0 0 10px; color:#718096; font-size:11px; font-style:italic;">
            Listed in execution order (first = outermost, runs first). Each duration is cumulative — it includes
            every middleware and the route handler nested inside it, not just its own processing time.
        </p>';

        // --- Waterfall-style list: indent grows with nesting depth ---
        $html .= '<div style="max-height: 220px; overflow-y:auto; font-family:monospace; font-size:11px; background:#111116; padding:10px; border-radius:4px; border:1px solid #2d2d3d;">';

        $maxDuration = max(array_map(static fn($r) => $r->durationMs, $records)) ?: 1.0;

        foreach ($records as $depth => $record) {
            $barWidth = max(2, (int) round(($record->durationMs / $maxDuration) * 100));
            $barColor = $record->failed ? '#ff5a5a' : '#7C6DFF';

            $html .= sprintf(
                '<div style="padding:5px 0; border-bottom:1px solid #1a1a24; color:#a0aec0; padding-left:%dpx;">
                    <div style="display:flex; justify-content:space-between; gap:12px; margin-bottom:3px;">
                        <span style="color:#c8cee8;">%s%s</span>
                        <span style="color:#718096; white-space:nowrap;">%.2f ms</span>
                    </div>
                    <div style="height:4px; background:#1a1a24; border-radius:2px; overflow:hidden;">
                        <div style="height:100%%; width:%d%%; background:%s;"></div>
                    </div>
                 </div>',
                min($depth, 8) * 10,
                htmlspecialchars($record->label),
                $record->failed ? ' (failed)' : '',
                $record->durationMs,
                $barWidth,
                $barColor
            );
        }

        $html .= '</div>';

        return $html;
    }
}