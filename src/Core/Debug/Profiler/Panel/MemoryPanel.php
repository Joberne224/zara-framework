<?php
declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Panel;

use Zara\Core\Debug\Profiler\PanelInterface;
use Zara\Core\Debug\Profiler\Profiler;

final class MemoryPanel implements PanelInterface
{
    public function __construct(private readonly Profiler $profiler) {}

    public function getId(): string    { return 'memory'; }
    public function getLabel(): string { return 'Memory'; }
    public function getIcon(): string  { return '🧠'; }

    public function getSummary(): string
    {
        return $this->format($this->profiler->getPeakMemory());
    }

    public function getBadgeColor(): string
    {
        $mb = $this->profiler->getPeakMemory() / 1024 / 1024;
        return match (true) {
            $mb > 64 => 'var(--zdb-err)',
            $mb > 32 => 'var(--zdb-warn)',
            default  => 'var(--zdb-ok)',
        };
    }

    public function renderContent(): string
    {
        $current = memory_get_usage(true);
        $peak    = $this->profiler->getPeakMemory();
        $start   = $this->profiler->getStartMemory();
        $delta   = $current - $start;
        $limit   = ini_get('memory_limit');

        $rows = [
            ['Startup memory',                $this->format($start)],
            ['Current memory',                $this->format($current)],
            ['Peak memory',                   $this->format($peak)],
            ['Delta (current − startup)',   ($delta >= 0 ? '+' : '') . $this->format(abs($delta))],
            ['PHP limit (memory_limit)',    $limit],
        ];

        $html = "<table class='zdb-table'><tbody>";
        foreach ($rows as [$label, $value]) {
            $html .= "<tr><td>{$label}</td><td class='zdb-num'>{$value}</td></tr>";
        }
        $html .= "</tbody></table>";

        // Visual bar: peak vs limit
        if ($limit !== '-1' && $limit !== false) {
            $limitBytes = $this->parseLimit($limit);
            if ($limitBytes > 0) {
                $pct  = min(100, round(($peak / $limitBytes) * 100, 1));
                $color = $pct > 80 ? 'var(--zdb-err)' : ($pct > 60 ? 'var(--zdb-warn)' : 'var(--zdb-ok)');
                $html .= "<div class='zdb-mt'>
                    <div class='zdb-label-row'>
                        <span>Peak / Limit</span><span class='zdb-num'>{$pct}%</span>
                    </div>
                    <div class='zdb-bar-track' style='height:12px'>
                        <div class='zdb-bar-fill' style='width:{$pct}%;background:{$color}'></div>
                    </div>
                </div>";
            }
        }

        return $html;
    }

    private function format(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    private function parseLimit(string $limit): int
    {
        $unit  = strtoupper(substr($limit, -1));
        $value = (int) $limit;
        return match ($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => $value,
        };
    }
}