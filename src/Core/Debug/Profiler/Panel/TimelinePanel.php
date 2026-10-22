<?php
declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Panel;

use Zara\Core\Debug\Profiler\PanelInterface;
use Zara\Core\Debug\Profiler\Profiler;

final class TimelinePanel implements PanelInterface
{
    public function __construct(private readonly Profiler $profiler) {}

    public function getId(): string    { return 'timeline'; }
    public function getLabel(): string { return 'Timeline'; }
    public function getIcon(): string  { return '⏱'; }

    public function getSummary(): string
    {
        return round($this->profiler->getTotalTime() * 1000, 2) . ' ms';
    }

    public function getBadgeColor(): string
    {
        $ms = $this->profiler->getTotalTime() * 1000;
        return match (true) {
            $ms > 1000 => 'var(--zdb-err)',
            $ms > 300  => 'var(--zdb-warn)',
            default    => 'var(--zdb-ok)',
        };
    }

    public function renderContent(): string
    {
        $timers   = $this->profiler->getTimers();
        $start    = $this->profiler->getStartTime();
        $total    = $this->profiler->getTotalTime();
        $totalMs  = $total * 1000;

        $rows = "<tr class='zdb-timeline-total'>
            <td><strong>Total request time</strong></td>
            <td class='zdb-num'>0.00 ms</td>
            <td><div class='zdb-bar-track'><div class='zdb-bar-fill zdb-timeline-total-fill'></div></div></td>
            <td class='zdb-num'><strong>" . number_format($totalMs, 2) . " ms</strong></td>
        </tr>";

        if (empty($timers)) {
            $rows .= "<tr><td colspan='4' class='zdb-muted zdb-timeline-empty'>No timers recorded. Use \$profiler->startTimer() / stopTimer().</td></tr>";
        } else {
            $maxMs = max($totalMs, 1.0);

            foreach ($timers as $id => $timer) {
                $timerStart  = ($timer['start'] - $start) * 1000;
                $timerEnd    = isset($timer['end']) ? ($timer['end'] - $start) * 1000 : $totalMs;
                $timerDur    = $timerEnd - $timerStart;
                $leftPct     = max(0.0, min(100.0, ($timerStart / $maxMs) * 100));
                $widthPct    = max(0.5, min(100.0 - $leftPct, ($timerDur / $maxMs) * 100));
                $label       = htmlspecialchars($timer['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $status      = isset($timer['end']) ? '' : ' <span class="zdb-badge zdb-badge-warn">running</span>';

                $rows .= "<tr>
                    <td class='zdb-timeline-event'>{$label}{$status}</td>
                    <td class='zdb-num'>" . number_format($timerStart, 2) . " ms</td>
                    <td><div class='zdb-bar-track'><div class='zdb-bar-fill' style='margin-left:{$leftPct}%;width:{$widthPct}%'></div></div></td>
                    <td class='zdb-num'>" . number_format($timerDur, 2) . " ms</td>
                </tr>";
            }
        }

        return "<table class='zdb-table zdb-timeline'>
            <thead>
                <tr>
                    <th class='zdb-timeline-event'>Event</th>
                    <th class='zdb-num'>Start</th>
                    <th>Timeline</th>
                    <th class='zdb-num'>Duration</th>
                </tr>
            </thead>
            <tbody>{$rows}</tbody>
        </table>";
    }
}
