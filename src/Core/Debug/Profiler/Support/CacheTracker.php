<?php
declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Support;

use Zara\Core\Config\Env;

/** Collects cache operations observed during the current request. */
final class CacheTracker
{
    private const MAX_RECORDS = 500;
    private static ?self $instance = null;

    /** @var list<array{operation:string,status:string,duration_ms:float,hits:int,misses:int,items:int}> */
    private array $records = [];
    private string $driver = 'Unavailable';
    private int $droppedCount = 0;

    public static function current(): self
    {
        return self::$instance ??= new self();
    }

    public function setDriver(string $driver): void
    {
        if (Env::isProfilerEnabled()) {
            $this->driver = $driver;
        }
    }

    public function record(
        string $operation,
        string $status,
        float $durationMs,
        int $hits = 0,
        int $misses = 0,
        int $items = 1,
    ): void {
        if (!Env::isProfilerEnabled()) {
            return;
        }

        if (count($this->records) >= self::MAX_RECORDS) {
            array_shift($this->records);
            ++$this->droppedCount;
        }

        $this->records[] = [
            'operation' => $operation,
            'status' => $status,
            'duration_ms' => max(0.0, $durationMs),
            'hits' => max(0, $hits),
            'misses' => max(0, $misses),
            'items' => max(0, $items),
        ];
    }

    /** @return list<array{operation:string,status:string,duration_ms:float,hits:int,misses:int,items:int}> */
    public function getRecords(): array
    {
        return $this->records;
    }

    /** @return array{calls:int,hits:int,misses:int,errors:int,total_ms:float,driver:string,dropped:int} */
    public function getStats(): array
    {
        return [
            'calls' => count($this->records),
            'hits' => array_sum(array_column($this->records, 'hits')),
            'misses' => array_sum(array_column($this->records, 'misses')),
            'errors' => count(array_filter(
                $this->records,
                static fn(array $record): bool => in_array($record['status'], ['Error', 'Failed'], true),
            )),
            'total_ms' => array_sum(array_column($this->records, 'duration_ms')),
            'driver' => $this->driver,
            'dropped' => $this->droppedCount,
        ];
    }
}
