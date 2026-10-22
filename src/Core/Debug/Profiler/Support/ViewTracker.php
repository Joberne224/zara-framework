<?php

declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Support;

/**
 * Collects every top-level template rendered on this request, hooked into
 * the single choke point TemplateEngine::render() wraps around.
 *
 * Nested {% include %}/{% extends %} never reach here separately — they're
 * resolved and inlined into the compiled PHP cache file at compile time
 * (BlockParser/IncludeParser), not via recursive render() calls at runtime.
 * So one record per render() call correctly represents one top-level view,
 * e.g. one per controller action — exactly what "rendered views" should show.
 *
 * Same static current() accessor pattern as QueryTracker: no install()/
 * timing bootstrap needed, since this isn't intercepting anything global
 * like autoload — a plain lazily-created singleton is enough.
 */
final class ViewTracker
{
    private const MAX_RECORDS = 500;
    private static ?self $instance = null;

    /** @var list<ViewRecord> */
    private array $records = [];
    private int $sequence = 0;
    private int $droppedCount = 0;
    private int $totalCount = 0;
    private int $compiledCount = 0;
    private int $cachedCount = 0;
    private int $failedCount = 0;
    private float $totalDurationMs = 0.0;

    public static function current(): self
    {
        return self::$instance ??= new self();
    }

    public function record(
        string $template,
        array $dataKeys,
        float $durationMs,
        bool $compiled,
        bool $failed,
        float $startedAt,
    ): void {
        ++$this->totalCount;
        $this->totalDurationMs += max(0.0, $durationMs);
        if ($failed) {
            ++$this->failedCount;
        } elseif ($compiled) {
            ++$this->compiledCount;
        } else {
            ++$this->cachedCount;
        }

        if (count($this->records) >= self::MAX_RECORDS) {
            array_shift($this->records);
            ++$this->droppedCount;
        }

        $this->records[] = new ViewRecord(
            order: $this->sequence++,
            template: $template,
            dataKeys: $dataKeys,
            durationMs: $durationMs,
            compiled: $compiled,
            failed: $failed,
            startedAt: $startedAt,
        );
    }

    /** @return list<ViewRecord> */
    public function getRecords(): array
    {
        return $this->records;
    }

    public function getTotalDurationMs(): float
    {
        return $this->totalDurationMs;
    }

    public function getTotalCount(): int
    {
        return $this->totalCount;
    }

    public function getCompiledCount(): int
    {
        return $this->compiledCount;
    }

    public function getCachedCount(): int
    {
        return $this->cachedCount;
    }

    public function getFailedCount(): int
    {
        return $this->failedCount;
    }

    public function getDroppedCount(): int
    {
        return $this->droppedCount;
    }

    /**
     * @return array<string, int> Template => render count, only entries > 1.
     *                             Rendering the same template more than once
     *                             per request isn't necessarily wrong (partial
     *                             re-renders, multi-section pages...) but is
     *                             worth surfacing as a signal.
     */
    public function getRepeatedTemplates(): array
    {
        $counts = [];
        foreach ($this->records as $record) {
            $counts[$record->template] = ($counts[$record->template] ?? 0) + 1;
        }

        return array_filter($counts, static fn(int $n) => $n > 1);
    }
}
