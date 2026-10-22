<?php
declare(strict_types=1);

namespace Zara\Database\Instrumentation;

interface QueryObserverInterface
{
    public function record(
        string $sql,
        int $paramCount,
        float $durationMs,
        string $method,
        bool $failed,
        bool $sqlAvailable,
        float $startedAt,
    ): void;
}
