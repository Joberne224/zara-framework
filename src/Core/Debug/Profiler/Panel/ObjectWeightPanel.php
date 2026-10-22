<?php

declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Panel;

use Zara\Core\Debug\Profiler\PanelInterface;
use Zara\Core\Debug\Profiler\Support\ContainerRecord;
use Zara\Core\Debug\Profiler\Support\ContainerTracker;

/**
 * Memory weight of the objects the container built on this request.
 * Reads the same ContainerTracker records as ContainerPanel; no extra hook.
 *
 * Each bar is compared to the heaviest object, so the shape of the list
 * (one giant vs. many equal ones) is visible at a glance.
 */
final class ObjectWeightPanel implements PanelInterface
{
    private const WARN_TOTAL_BYTES = 2 * 1024 * 1024;
    private const ERROR_TOTAL_BYTES = 8 * 1024 * 1024;

    // Share of the total that makes a single object worth a look.
    private const HEAVY_SHARE = 0.25;
    private const NOTABLE_SHARE = 0.10;

    public function getId(): string
    {
        return 'object-weight';
    }

    public function getLabel(): string
    {
        return 'Weight';
    }

    public function getIcon(): string
    {
        return '⚖️';
    }

    public function getSummary(): string
    {
        $tracker = ContainerTracker::current();

        return sprintf(
            '%s across %d object%s',
            self::formatBytes($tracker->getTotalSelfMemoryBytes()),
            $tracker->getFreshCount(),
            $tracker->getFreshCount() > 1 ? 's' : ''
        );
    }

    public function getBadgeColor(): string
    {
        $total = ContainerTracker::current()->getTotalSelfMemoryBytes();

        return match (true) {
            $total > self::ERROR_TOTAL_BYTES => 'var(--zdb-err)',
            $total > self::WARN_TOTAL_BYTES => 'var(--zdb-warn)',
            default => 'var(--zdb-ok)',
        };
    }

    public function renderContent(): string
    {
        $tracker = ContainerTracker::current();

        $built = array_values(array_filter(
            $tracker->getRecords(),
            static fn(ContainerRecord $r) => !$r->reused && !$r->failed
        ));

        if ($built === []) {
            return '<h3 style="margin-top:0;color:#6d4aff;">Weight</h3>
                    <p style="color:#718096; font-style:italic;">No object built on this request.</p>';
        }

        usort($built, static fn(ContainerRecord $a, ContainerRecord $b) => $b->selfMemoryBytes <=> $a->selfMemoryBytes);

        $total = $tracker->getTotalSelfMemoryBytes();
        $heaviest = max(1, $built[0]->selfMemoryBytes);

        $html = sprintf(
            '<h3 style="margin-top:0;color:#6d4aff;">Weight (%s across %d objects)</h3>',
            self::formatBytes($total),
            count($built)
        );

        $html .= sprintf(
            '<p style="margin:0 0 10px; color:#718096; font-size:11px;">Own weight per object (dependencies excluded), bars compared to the heaviest: <span style="color:#c8cee8;">%s</span>. %d reused from cache (0 B).</p>',
            htmlspecialchars($built[0]->id),
            $tracker->getReusedCount()
        );

        $html .= '<div class="zdb-fill" style="font-family:monospace; font-size:11px; background:#111116; padding:10px; border-radius:4px; border:1px solid #2d2d3d;">';

        foreach ($built as $record) {
            $share = $total > 0 ? $record->selfMemoryBytes / $total : 0.0;
            $barColor = match (true) {
                $share >= self::HEAVY_SHARE => '#ff5a5a',
                $share >= self::NOTABLE_SHARE => '#ff9d4a',
                default => '#7C6DFF',
            };

            $cumulative = $record->memoryBytes > $record->selfMemoryBytes
                ? sprintf(' · %s with dependencies', self::formatBytes($record->memoryBytes))
                : '';

            $html .= sprintf(
                '<div style="padding:5px 0; border-bottom:1px solid #1a1a24; color:#a0aec0;">
                    <div style="display:flex; justify-content:space-between; gap:12px; margin-bottom:3px;">
                        <span style="color:#c8cee8;">%s</span>
                        <span style="color:%s; white-space:nowrap;">%s (%.0f%%)</span>
                    </div>
                    <div style="height:6px; background:#1a1a24; border-radius:3px; overflow:hidden;">
                        <div style="width:%.1f%%; height:100%%; background:%s;"></div>
                    </div>
                    <div style="color:#5a6080; font-size:10px; margin-top:2px;">%s%s</div>
                 </div>',
                htmlspecialchars($record->id),
                $barColor,
                self::formatBytes($record->selfMemoryBytes),
                $share * 100,
                $record->selfMemoryBytes / $heaviest * 100,
                $barColor,
                $record->resolvedClass !== null ? htmlspecialchars($record->resolvedClass) : '',
                $cumulative
            );
        }

        $html .= '</div>';

        return $html;
    }

    private static function formatBytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1024 * 1024 => sprintf('%.2f MB', $bytes / (1024 * 1024)),
            $bytes >= 1024 => sprintf('%.1f KB', $bytes / 1024),
            default => sprintf('%d B', $bytes),
        };
    }
}