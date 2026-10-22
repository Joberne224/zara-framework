<?php

declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Support;

/**
 * Collects every service resolution attempt through ServiceFactory::get(),
 * hooked into a single try/catch/finally wrapping the whole method body
 * (after the alias short-circuit, which recurses into get() for the real
 * target id — tracking it too would double-count the same logical
 * resolution under two ids).
 *
 * Same static current() accessor pattern as QueryTracker/ViewTracker.
 */
final class ContainerTracker
{
    private static ?self $instance = null;

    /** @var list<ContainerRecord> */
    private array $records = [];
    private int $sequence = 0;

    /**
     * Cumulative bytes of already-recorded services, grouped by depth.
     * Children are recorded before their parent (record() runs in a finally),
     * so a parent can subtract its direct children's weight when it arrives.
     *
     * @var array<int, int>
     */
    private array $pendingMemoryByDepth = [];

    public static function current(): self
    {
        return self::$instance ??= new self();
    }

    public function record(
        string $id,
        ?string $resolvedClass,
        float $durationMs,
        bool $reused,
        bool $failed,
        int $memoryBytes = 0,
        int $depth = 0,
        float $startedAt = 0.0,
    ): void {
        $childrenBytes = $this->pendingMemoryByDepth[$depth + 1] ?? 0;
        $this->pendingMemoryByDepth[$depth + 1] = 0;
        $this->pendingMemoryByDepth[$depth] = ($this->pendingMemoryByDepth[$depth] ?? 0) + $memoryBytes;

        $this->records[] = new ContainerRecord(
            order: $this->sequence++,
            id: $id,
            resolvedClass: $resolvedClass,
            durationMs: $durationMs,
            reused: $reused,
            failed: $failed,
            memoryBytes: $memoryBytes,
            selfMemoryBytes: max(0, $memoryBytes - $childrenBytes),
            depth: $depth,
            startedAt: $startedAt,
        );
    }

    /** @return list<ContainerRecord> */
    public function getRecords(): array
    {
        return $this->records;
    }

    public function getTotalDurationMs(): float
    {
        return array_sum(array_map(static fn(ContainerRecord $r) => $r->durationMs, $this->records));
    }

    public function getFreshCount(): int
    {
        return count(array_filter($this->records, static fn(ContainerRecord $r) => !$r->reused && !$r->failed));
    }

    public function getReusedCount(): int
    {
        return count(array_filter($this->records, static fn(ContainerRecord $r) => $r->reused));
    }

    /** Sum of own weights: every byte counted once, unlike cumulative values. */
    public function getTotalSelfMemoryBytes(): int
    {
        return array_sum(array_map(static fn(ContainerRecord $r) => $r->selfMemoryBytes, $this->records));
    }

    /** @return list<ContainerRecord> */
    public function getFailedRecords(): array
    {
        return array_values(array_filter($this->records, static fn(ContainerRecord $r) => $r->failed));
    }
}
