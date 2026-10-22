<?php
declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Support;

final class ClassLoadRecord
{
    public function __construct(
        public readonly string $class,
        public readonly int $order,
        public readonly float $timeOffsetMs,
        public readonly int $memoryDeltaBytes,
        public ClassLoadSource $source = ClassLoadSource::Autoload,
        public ?string $serviceId = null, // container id (interface/alias) if resolved via the container
    ) {
    }
}