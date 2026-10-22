<?php

declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Support;

final class QueryRecord
{
    public function __construct(
        public readonly int $order,
        public readonly string $sql,
        public readonly int $paramCount,
        public readonly float $durationMs,
        public readonly string $method, // 'query'|'execute'|'insert'|'update'|'delete'|'fetchOne'|'fetchColumn'
        public readonly bool $failed,
        // False when $logQueries is disabled on the database instance — the sql
        // field then holds the redacted placeholder, not real SQL text. N+1
        // detection must skip these records rather than falsely group them.
        public readonly bool $sqlAvailable,
        // Best-effort primary table name extracted from $sql by SqlTableExtractor.
        // Used only for grouping in the profiler UI — never for query execution,
        // validation, or anything security-sensitive. Null when $sqlAvailable is
        // false (redacted SQL) or when the extraction pattern didn't match
        // (subqueries, CTEs, unusual dialects).
        public readonly ?string $table,
        public readonly float $startedAt,
    ) {
    }
}
