<?php
declare(strict_types=1);

namespace Zara\Core\Debug\Profiler;

use Zara\Core\Config\Env;

/**
 * Collecteur central du profiler.
 *
 * One instance per request: created once by the container (singleton scope
 * in services.yaml) and shared by the middleware and panel data handlers.
 *
 * Panel entry points:
 *   - startTimer() / stopTimer()  → TimelinePanel
 *   - addLog()                    → LogPanel (called by MonologProfilerHandler)
 *
 * MemoryPanel reads PHP's peak-memory counter directly. RequestPanel receives
 * the current request and applies redaction before displaying its data.
 *
 * Usage in a service:
 *   $profiler->startTimer('db.query');
 *   // ... execute work ...
 *   $profiler->stopTimer('db.query');
 */
final class Profiler
{
    private const MAX_LOG_ENTRIES = 500;

    /** @var array<string, array{label: string, start: float, end?: float}> */
    private array $timers = [];

    /** @var array<array{level: string, message: string, context: array, time: float}> */
    private array $logs = [];
    private int $droppedLogCount = 0;

    /** @var list<PanelInterface> */
    private array $panels = [];

    private float $startTime;
    private int   $startMemory;

    public function __construct()
    {
        $this->startTime   = defined('ZARA_START') ? ZARA_START : microtime(true);
        $this->startMemory = memory_get_usage(true);
    }

    // -------------------------------------------------------------------------
    // Timers
    // -------------------------------------------------------------------------

    public function startTimer(string $id, string $label = ''): void
    {
        if (!Env::isProfilerEnabled()) {
            return;
        }

        $this->timers[$id] = [
            'label' => $label ?: $id,
            'start' => microtime(true),
        ];
    }

    public function stopTimer(string $id): void
    {
        if (!Env::isProfilerEnabled()) {
            return;
        }

        if (isset($this->timers[$id]) && !isset($this->timers[$id]['end'])) {
            $this->timers[$id]['end'] = microtime(true);
        }
    }

    /** @return array<string, array{label: string, start: float, end?: float}> */
    public function getTimers(): array
    {
        return $this->timers;
    }

    public function getStartTime(): float
    {
        return $this->startTime;
    }

    public function getStartMemory(): int
    {
        return $this->startMemory;
    }

    // -------------------------------------------------------------------------
    // Logs
    // -------------------------------------------------------------------------

    public function addLog(string $level, string $message, array $context = []): void
    {
        if (!Env::isProfilerEnabled()) {
            return;
        }

        if (count($this->logs) >= self::MAX_LOG_ENTRIES) {
            array_shift($this->logs);
            ++$this->droppedLogCount;
        }

        $this->logs[] = [
            'level'   => $level,
            'message' => $message,
            'context' => $context,
            'time'    => microtime(true),
        ];
    }

    /** @return array<array{level: string, message: string, context: array, time: float}> */
    public function getLogs(): array
    {
        return $this->logs;
    }

    /** Number of older log entries omitted after reaching the in-memory cap. */
    public function getDroppedLogCount(): int
    {
        return $this->droppedLogCount;
    }

    // -------------------------------------------------------------------------
    // Panels
    // -------------------------------------------------------------------------

    public function addPanel(PanelInterface $panel): self
    {
        if (!Env::isProfilerEnabled()) {
            return $this;
        }

        foreach ($this->panels as $index => $registeredPanel) {
            if ($registeredPanel->getId() === $panel->getId()) {
                // Keep one panel per DOM id; replacing in place preserves tab order.
                $this->panels[$index] = $panel;
                return $this;
            }
        }

        $this->panels[] = $panel;
        return $this;
    }

    /** @return list<PanelInterface> */
    public function getPanels(): array
    {
        return $this->panels;
    }

    // -------------------------------------------------------------------------
    // Global metrics
    // -------------------------------------------------------------------------

    public function getTotalTime(): float
    {
        return microtime(true) - $this->startTime;
    }

    public function getPeakMemory(): int
    {
        return memory_get_peak_usage(true);
    }
}
