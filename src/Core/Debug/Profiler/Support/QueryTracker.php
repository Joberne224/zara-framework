<?php

declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Support;

use Zara\Database\Instrumentation\QueryObserverInterface;

/**
 * Collects every SQL statement actually executed on this request, hooked
 * registered as the Database component's optional query observer by
 * ProfilerMiddleware.
 *
 * Unlike ClassLoadTracker, this does not need an install()/timing-sensitive
 * bootstrap step — it has no external side effect to register (no autoload
 * interception), so a plain lazily-created singleton is enough. Database
 * classes (MysqlDatabase/SqliteDatabase/PostgresDatabase) remain independent
 * of Core; the profiler middleware installs this observer for the request.
 */
final class QueryTracker implements QueryObserverInterface
{
    private static ?self $instance = null;

    /** @var list<QueryRecord> */
    private array $records = [];
    private int $sequence = 0;

    public static function current(): self
    {
        return self::$instance ??= new self();
    }

    public function record(
        string $sql,
        int $paramCount,
        float $durationMs,
        string $method,
        bool $failed,
        bool $sqlAvailable,
        float $startedAt,
    ): void {
        $this->records[] = new QueryRecord(
            order: $this->sequence++,
            sql: $sql,
            paramCount: $paramCount,
            durationMs: $durationMs,
            method: $method,
            failed: $failed,
            sqlAvailable: $sqlAvailable,
            // Redacted SQL can't be parsed for a table name — grouping must
            // skip these records the same way getRepeatedQueries() does.
            table: $sqlAvailable ? SqlTableExtractor::extract($sql) : null,
            startedAt: $startedAt,
        );
    }

    /** @return list<QueryRecord> */
    public function getRecords(): array
    {
        return $this->records;
    }

    public function getTotalDurationMs(): float
    {
        return array_sum(array_map(static fn(QueryRecord $r) => $r->durationMs, $this->records));
    }

    /**
     * Groups queries by their exact (already-parameterized) SQL text. Since
     * every query in this codebase goes through prepared statements, two
     * executions of the same query shape share the exact same "?"-placeholder
     * SQL string — no normalization needed. Records whose SQL was redacted
     * (logQueries disabled) are excluded to avoid collapsing unrelated
     * queries under the same "[query text redacted]" placeholder.
     *
     * @return array<string, int> SQL text => repeat count, only entries > 1.
     */
    public function getRepeatedQueries(): array
    {
        $counts = [];
        foreach ($this->records as $record) {
            if (!$record->sqlAvailable) {
                continue;
            }
            $counts[$record->sql] = ($counts[$record->sql] ?? 0) + 1;
        }

        return array_filter($counts, static fn(int $n) => $n > 1);
    }

    /** True if at least one record has SQL text redacted (logQueries=false). */
    public function hasRedactedQueries(): bool
    {
        foreach ($this->records as $record) {
            if (!$record->sqlAvailable) {
                return true;
            }
        }

        return false;
    }
}
