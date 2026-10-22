<?php

declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Support;

/**
 * Collects every stage (middleware) actually run through Zara\Core\Support\
 * Pipeline, hooked into the single innermost closure Pipeline::carry()
 * builds around each stage invocation.
 *
 * Pipeline is a generic staged-execution utility (not HTTP-specific by
 * namespace), so in principle anything using it would show up here — in
 * practice this is dominated by the HTTP middleware stack Dispatcher builds
 * per request, which is exactly what this panel is meant to surface.
 *
 * Order is captured at STAGE ENTRY, not completion: because stages nest
 * (each calls the next one before returning), the innermost stage actually
 * finishes first — recording by completion order would show the chain
 * reversed from how it was registered. reserveOrder() lets Pipeline grab a
 * sequence number the moment a stage starts, before it calls downstream.
 *
 * Same static current() accessor pattern as the other trackers.
 */
final class MiddlewareTracker
{
    private static ?self $instance = null;

    /** @var list<MiddlewareRecord> */
    private array $records = [];
    private int $sequence = 0;

    public static function current(): self
    {
        return self::$instance ??= new self();
    }

    public function reserveOrder(): int
    {
        return $this->sequence++;
    }

    public function record(int $order, string $label, float $durationMs, bool $failed, float $startedAt): void
    {
        $this->records[] = new MiddlewareRecord(
            order: $order,
            label: $label,
            durationMs: $durationMs,
            failed: $failed,
            startedAt: $startedAt,
        );
    }

    /** @return list<MiddlewareRecord> Sorted by entry order. */
    public function getRecords(): array
    {
        $records = $this->records;
        usort($records, static fn(MiddlewareRecord $a, MiddlewareRecord $b) => $a->order <=> $b->order);

        return $records;
    }

    public function getTotalDurationMs(): float
    {
        // Not a sum: durations are cumulative/nested, so the outermost
        // stage's duration already represents the total pipeline time.
        $records = $this->getRecords();

        return $records === [] ? 0.0 : $records[0]->durationMs;
    }
}
