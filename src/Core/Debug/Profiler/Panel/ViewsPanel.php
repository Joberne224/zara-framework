<?php

declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Panel;

use Zara\Core\Debug\Profiler\PanelInterface;
use Zara\Core\Debug\Profiler\Support\ViewTracker;

final class ViewsPanel implements PanelInterface
{
    private const SLOW_VIEW_MS = 30.0;
    private const WARN_COUNT = 15;
    private const ERROR_COUNT = 30;

    public function getId(): string
    {
        return 'views';
    }

    public function getLabel(): string
    {
        return 'Views';
    }

    public function getIcon(): string
    {
        return '🖼️';
    }

    public function getSummary(): string
    {
        $records = ViewTracker::current()->getRecords();
        $count = ViewTracker::current()->getTotalCount();
        $totalMs = ViewTracker::current()->getTotalDurationMs();

        return sprintf('%d view%s (%.1f ms)', $count, $count > 1 ? 's' : '', $totalMs);
    }

    public function getBadgeColor(): string
    {
        $count = ViewTracker::current()->getTotalCount();

        return match (true) {
            $count > self::ERROR_COUNT => 'var(--zdb-err)',
            $count > self::WARN_COUNT => 'var(--zdb-warn)',
            default => 'var(--zdb-ok)',
        };
    }

    public function renderContent(): string
    {
        $tracker = ViewTracker::current();
        $records = $tracker->getRecords();

        if ($records === []) {
            return '<h3 style="margin-top:0;color:#6d4aff;">Views</h3>
                    <p style="color:#718096; font-style:italic;">No view rendered on this request.</p>';
        }

        $count = $tracker->getTotalCount();
        $totalMs = $tracker->getTotalDurationMs();
        $compiledCount = $tracker->getCompiledCount();
        $cachedCount = $tracker->getCachedCount();
        $failedCount = $tracker->getFailedCount();

        $html = sprintf('<h3 style="margin-top:0;color:#6d4aff;">Views (%d — %.1f ms total)</h3>', $count, $totalMs);

        $html .= sprintf(
            '<p style="margin:0 0 10px; color:#718096; font-size:11px;">%d compiled, %d served from cache, %d failed</p>',
            $compiledCount,
            $cachedCount,
            $failedCount,
        );

        // --- Repeated templates alert ---
        $repeated = $tracker->getRepeatedTemplates();
        if ($repeated !== []) {
            arsort($repeated);
            $html .= '<div style="margin-bottom:10px; padding:8px; background:#2a1a1a; border:1px solid #ff9d4a; border-radius:4px;">
                <div style="color:#ff9d4a; font-weight:bold; margin-bottom:4px;">⚠ Same template rendered more than once</div>
                <div style="color:#a08060; font-size:10px; font-style:italic; margin-bottom:6px;">Not necessarily wrong (partial re-renders, multi-section pages...), but worth a look if unexpected.</div>
                <div style="font-family:monospace; font-size:11px; color:#d0a080; max-height:100px; overflow-y:auto;">';
            foreach ($repeated as $template => $n) {
                $html .= sprintf('<div style="padding:2px 0;">%d× — %s</div>', $n, self::escape($template));
            }
            $html .= '</div></div>';
        }

        // --- Scrollable view list ---
        $html .= '<div style="max-height: 220px; overflow-y:auto; font-family:monospace; font-size:11px; background:#111116; padding:10px; border-radius:4px; border:1px solid #2d2d3d;">';

        foreach ($records as $record) {
            $isSlow = $record->durationMs > self::SLOW_VIEW_MS;
            $renderStatus = $record->failed
                ? '<span style="color:#ff5a5a;">failed</span>'
                : ($record->compiled
                    ? '<span style="color:#ff9d4a;">compiled</span>'
                    : '<span style="color:#4AFFA0;">cached</span>');
            $dataKeysDisplay = $record->dataKeys === []
                ? '<span style="color:#5a6080; font-style:italic;">no data</span>'
                : self::escape(implode(', ', $record->dataKeys));

            $html .= sprintf(
                '<div style="padding:5px 0; border-bottom:1px solid #1a1a24; color:#a0aec0;">
                    <div style="display:flex; justify-content:space-between; gap:12px; margin-bottom:2px;">
                        <span style="color:%s; font-weight:bold;">%s%s</span>
                        <span style="color:%s; white-space:nowrap;">%.1f ms</span>
                    </div>
                    <div style="color:#5a6080; font-size:10px;">%s%s</div>
                 </div>',
                $record->failed ? '#ff5a5a' : '#7C6DFF',
                self::escape($record->template),
                $record->failed ? ' (failed)' : '',
                $isSlow ? '#ff9d4a' : '#718096',
                $record->durationMs,
                $renderStatus,
                ' · ' . $dataKeysDisplay
            );
        }

        $html .= '</div>';

        if ($tracker->getDroppedCount() > 0) {
            $html .= '<p style="margin:8px 0 0;color:#718096;font-size:11px;">'
                . $tracker->getDroppedCount() . ' older view records were omitted to limit profiler memory use.</p>';
        }

        return $html;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
