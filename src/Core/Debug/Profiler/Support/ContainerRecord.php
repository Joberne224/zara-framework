<?php

declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Support;

final class ContainerRecord
{
    public function __construct(
        public readonly int $order,
        public readonly string $id,
        public readonly ?string $resolvedClass, // null when resolution failed before a class was known
        public readonly float $durationMs,
        public readonly bool $reused, // true = returned from InstanceStorage cache, no construction work
        public readonly bool $failed,
        public readonly int $memoryBytes = 0,     // cumulative: includes dependencies resolved inside this call
        public readonly int $selfMemoryBytes = 0, // memoryBytes minus direct children (own weight)
        public readonly int $depth = 0,           // 0 = requested from outside the container
        public readonly float $startedAt = 0.0,
    ) {
    }
}
