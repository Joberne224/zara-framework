<?php

declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Support;

final class ViewRecord
{
    /**
     * @param list<string> $dataKeys Keys of the $data array passed to render()
     *                               — not the values, to avoid dumping large
     *                               or sensitive business objects into the
     *                               debugbar by default.
     */
    public function __construct(
        public readonly int $order,
        public readonly string $template,
        public readonly array $dataKeys,
        public readonly float $durationMs,
        public readonly bool $compiled,
        public readonly bool $failed,
        public readonly float $startedAt,
    ) {
    }
}
