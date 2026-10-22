<?php

declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Panel;

use Zara\Core\Debug\Profiler\PanelInterface;
use Zara\Core\Debug\Profiler\Support\ContainerTracker;

final class ContainerPanel implements PanelInterface
{
    private const SLOW_RESOLUTION_MS = 5.0;
    private const WARN_COUNT = 200;
    private const ERROR_COUNT = 400;

    public function getId(): string
    {
        return 'container';
    }

    public function getLabel(): string
    {
        return 'Container';
    }

    public function getIcon(): string
    {
        return '📦';
    }

    public function getSummary(): string
    {
        $tracker = ContainerTracker::current();
        $count = count($tracker->getRecords());
        $totalMs = $tracker->getTotalDurationMs();

        return sprintf('%d resolution%s (%.1f ms)', $count, $count > 1 ? 's' : '', $totalMs);
    }

    public function getBadgeColor(): string
    {
        $tracker = ContainerTracker::current();

        if ($tracker->getFailedRecords() !== []) {
            return 'var(--zdb-err)';
        }

        $count = count($tracker->getRecords());

        return match (true) {
            $count > self::ERROR_COUNT => 'var(--zdb-err)',
            $count > self::WARN_COUNT => 'var(--zdb-warn)',
            default => 'var(--zdb-ok)',
        };
    }

    public function renderContent(): string
    {
        $tracker = ContainerTracker::current();
        $records = $tracker->getRecords();

        if ($records === []) {
            return '<h3 style="margin-top:0;color:#6d4aff;">Container</h3>
                    <p style="color:#718096; font-style:italic;">No service resolved on this request.</p>';
        }

        $count = count($records);
        $totalMs = $tracker->getTotalDurationMs();
        $fresh = $tracker->getFreshCount();
        $reused = $tracker->getReusedCount();
        $failed = $tracker->getFailedRecords();

        $html = sprintf('<h3 style="margin-top:0;color:#6d4aff;">Container (%d resolutions — %.1f ms total)</h3>', $count, $totalMs);

        $html .= sprintf(
            '<p style="margin:0 0 10px; color:#718096; font-size:11px;">%d fresh, %d reused from cache%s</p>',
            $fresh,
            $reused,
            $failed !== [] ? sprintf(', <span style="color:#ff5a5a;">%d failed</span>', count($failed)) : ''
        );

        // --- Failed resolutions alert ---
        if ($failed !== []) {
            $html .= '<div style="margin-bottom:10px; padding:8px; background:#2a1a1a; border:1px solid #ff5a5a; border-radius:4px;">
                <div style="color:#ff5a5a; font-weight:bold; margin-bottom:4px;">⚠ Failed resolutions</div>
                <div style="font-family:monospace; font-size:11px; color:#ff9d9d; max-height:100px; overflow-y:auto;">';
            foreach ($failed as $record) {
                $html .= sprintf('<div style="padding:2px 0;">%s (%.2f ms)</div>', htmlspecialchars($record->id), $record->durationMs);
            }
            $html .= '</div></div>';
        }

        // --- Scrollable resolution list ---
        $html .= '<div class="zdb-fill" style="font-family:monospace; font-size:11px; background:#111116; padding:10px; border-radius:4px; border:1px solid #2d2d3d;">';

        foreach ($records as $record) {
            $isSlow = $record->durationMs > self::SLOW_RESOLUTION_MS;

            $statusColor = match (true) {
                $record->failed => '#ff5a5a',
                $record->reused => '#7C6DFF',
                default => '#4AFFA0',
            };
            $statusLabel = match (true) {
                $record->failed => 'failed',
                $record->reused => 'reused',
                default => 'fresh',
            };

            $html .= sprintf(
                '<div style="padding:5px 0; border-bottom:1px solid #1a1a24; color:#a0aec0;">
                    <div style="display:flex; justify-content:space-between; gap:12px; margin-bottom:2px;">
                        <span style="color:#c8cee8;">%s</span>
                        <span style="color:%s; white-space:nowrap;">%.2f ms</span>
                    </div>
                    <div style="color:#5a6080; font-size:10px;">
                        <span style="color:%s;">%s</span>%s
                    </div>
                 </div>',
                htmlspecialchars($record->id),
                $isSlow ? '#ff9d4a' : '#718096',
                $record->durationMs,
                $statusColor,
                $statusLabel,
                $record->resolvedClass !== null ? ' · ' . htmlspecialchars($record->resolvedClass) : ''
            );
        }

        $html .= '</div>';

        return $html;
    }
}