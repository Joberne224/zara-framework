<?php
declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Panel;

use Zara\Core\Debug\Profiler\PanelInterface;
use Zara\Core\Debug\Profiler\Profiler;
use Zara\Core\Debug\Profiler\Support\ContainerTracker;
use Zara\Core\Debug\Profiler\Support\MiddlewareTracker;
use Zara\Core\Debug\Profiler\Support\QueryTracker;
use Zara\Core\Debug\Profiler\Support\ViewTracker;

/** Aggregated request metrics and a cross-component timing waterfall. */
final class PerformancePanel implements PanelInterface
{
    public function __construct(private readonly Profiler $profiler) {}

    public function getId(): string { return 'performance'; }
    public function getLabel(): string { return 'Performance'; }
    public function getIcon(): string { return '📈'; }

    public function getSummary(): string
    {
        return number_format($this->profiler->getTotalTime() * 1000, 1) . ' ms';
    }

    public function getBadgeColor(): string
    {
        $ms = $this->profiler->getTotalTime() * 1000;
        return $ms > 1000 ? 'var(--zdb-err)' : ($ms > 300 ? 'var(--zdb-warn)' : 'var(--zdb-ok)');
    }

    public function renderContent(): string
    {
        $queries = QueryTracker::current()->getRecords();
        $middlewares = MiddlewareTracker::current()->getRecords();
        $views = ViewTracker::current()->getRecords();
        $services = ContainerTracker::current()->getRecords();
        $totalMs = max(0.001, $this->profiler->getTotalTime() * 1000);
        $rows = [];

        foreach ($this->profiler->getTimers() as $timer) {
            $start = ($timer['start'] - $this->profiler->getStartTime()) * 1000;
            $end = (isset($timer['end']) ? $timer['end'] : microtime(true)) - $this->profiler->getStartTime();
            $rows[] = $this->event($timer['label'], 'Timer', $start, max(0, $end * 1000 - $start), isset($timer['end']) ? null : 'Running', '#a78bfa');
        }
        foreach ($middlewares as $record) {
            $rows[] = $this->event($record->label, 'Middleware', ($record->startedAt - $this->profiler->getStartTime()) * 1000, $record->durationMs, $record->failed ? 'Failed' : null, '#f59e0b');
        }
        foreach ($queries as $record) {
            $label = $record->table !== null ? $record->method . ' · ' . $record->table : $record->method . ' query';
            $rows[] = $this->event($label, 'Database', ($record->startedAt - $this->profiler->getStartTime()) * 1000, $record->durationMs, $record->failed ? 'Failed' : null, '#60a5fa');
        }
        foreach ($views as $record) {
            $rows[] = $this->event($record->template, 'View', ($record->startedAt - $this->profiler->getStartTime()) * 1000, $record->durationMs, $record->failed ? 'Failed' : null, '#34d399');
        }
        foreach ($services as $record) {
            $rows[] = $this->event($record->id, 'Service', ($record->startedAt - $this->profiler->getStartTime()) * 1000, $record->durationMs, $record->failed ? 'Failed' : null, '#c084fc');
        }

        usort($rows, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);
        $waterfall = '';
        foreach ($rows as $row) {
            // Trackers are request-global singletons. Ignore any stale/out-of-window
            // records defensively, and clamp intervals to the visible request range.
            if ($row['start'] < -1 || $row['start'] > $totalMs) continue;
            $start = max(0.0, $row['start']);
            $duration = min(max(0.0, $row['duration']), max(0.0, $totalMs - $start));
            $left = min(100.0, $start / $totalMs * 100);
            $width = min(100.0 - $left, max(0.0, $duration / $totalMs * 100));
            $bar = $width > 0 ? '<span style="left:' . $left . '%;width:' . $width . '%;background:' . $row['color'] . '"></span>' : '';
            $label = self::escape($row['label']);
            $type = self::escape($row['type']);
            $status = $row['status'] ? '<span class="zdb-perf-status">' . self::escape($row['status']) . '</span>' : '';
            $waterfall .= '<tr><td class="zdb-perf-label" title="' . $label . '">' . $label . $status . '</td><td>' . $type . '</td><td class="zdb-num">' . number_format($start, 2) . ' ms</td><td><div class="zdb-perf-track">' . $bar . '</div></td><td class="zdb-num">' . number_format($duration, 2) . ' ms</td></tr>';
        }

        if ($waterfall === '') {
            $waterfall = '<tr><td colspan="5" class="zdb-muted zdb-perf-empty">No performance events were recorded for this request.</td></tr>';
        }

        $queryTime = QueryTracker::current()->getTotalDurationMs();
        $middlewareTime = MiddlewareTracker::current()->getTotalDurationMs();
        $viewTime = ViewTracker::current()->getTotalDurationMs();
        $metrics = [
            ['Request time', number_format($totalMs, 2) . ' ms'],
            ['Peak memory', self::formatBytes($this->profiler->getPeakMemory())],
            ['Database', count($queries) . ' queries · ' . number_format($queryTime, 2) . ' ms'],
            ['Middleware', count($middlewares) . ' stages · ' . number_format($middlewareTime, 2) . ' ms'],
            ['Views', count($views) . ' renders · ' . number_format($viewTime, 2) . ' ms'],
            ['Services', count($services) . ' resolutions'],
        ];
        $cards = '';
        foreach ($metrics as [$name, $value]) {
            $cards .= '<div class="zdb-perf-card"><span>' . self::escape($name) . '</span><strong>' . self::escape($value) . '</strong></div>';
        }

        return '<section class="zdb-perf"><div class="zdb-perf-summary">' . $cards . '</div>'
            . '<div class="zdb-perf-heading"><h3>Request waterfall</h3><span>0.00 ms <i></i> ' . number_format($totalMs, 2) . ' ms</span></div>'
            . '<p class="zdb-perf-note">Middleware durations include downstream work, so nested bars overlap by design.</p>'
            . '<table class="zdb-table zdb-perf-table"><thead><tr><th>Event</th><th>Type</th><th class="zdb-num">Start</th><th>Timeline</th><th class="zdb-num">Duration</th></tr></thead><tbody>' . $waterfall . '</tbody></table></section>';
    }

    /** @return array{label:string,type:string,start:float,duration:float,status:?string,color:string} */
    private function event(string $label, string $type, float $start, float $duration, ?string $status, string $color): array
    {
        return ['label' => $label, 'type' => $type, 'start' => $start, 'duration' => $duration, 'status' => $status, 'color' => $color];
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        foreach ($units as $unit) {
            if ($value < 1024 || $unit === 'GB') return number_format($value, $unit === 'B' ? 0 : 2) . ' ' . $unit;
            $value /= 1024;
        }
        return number_format($value, 2) . ' GB';
    }
}
