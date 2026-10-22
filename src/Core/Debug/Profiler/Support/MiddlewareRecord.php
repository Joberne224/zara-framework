<?php

declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Support;

final class MiddlewareRecord
{
    public function __construct(
        public readonly int $order,        // capture order = entry order (registration order), not completion order
        public readonly string $label,     // class name, or 'Closure' for inline middleware
        public readonly float $durationMs, // cumulative: includes every nested stage called downstream
        public readonly bool $failed,
        public readonly float $startedAt,
    ) {
    }
}
