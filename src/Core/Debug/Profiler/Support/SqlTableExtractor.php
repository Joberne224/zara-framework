<?php

declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Support;

/**
 * Best-effort extraction of the primary table targeted by a SQL statement.
 * Used exclusively to group queries in the debug profiler UI — this is
 * intentionally not a SQL parser and must never be relied on for query
 * validation, security decisions, or anything beyond display.
 *
 * Matches, in priority order, the target of INSERT INTO / UPDATE /
 * DELETE FROM, falling back to the first FROM clause (covers SELECT, and
 * still catches the outer query's table when a subquery is present, since
 * the outer FROM is scanned left-to-right first). Identifiers optionally
 * wrapped in backticks, double quotes, or brackets are unwrapped.
 *
 * Returns null when nothing matches — CTEs, dialect-specific syntax, or
 * statements with no single clear target table are simply left ungrouped
 * ("Autre" bucket) by the caller rather than mis-attributed.
 */
final class SqlTableExtractor
{
    private const IDENTIFIER = '[`"\[]?([a-zA-Z_][a-zA-Z0-9_]*)[`"\]]?';

    public static function extract(string $sql): ?string
    {
        $patterns = [
            '/^\s*INSERT\s+INTO\s+' . self::IDENTIFIER . '/i',
            '/^\s*UPDATE\s+' . self::IDENTIFIER . '/i',
            '/^\s*DELETE\s+FROM\s+' . self::IDENTIFIER . '/i',
            '/\bFROM\s+' . self::IDENTIFIER . '/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sql, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }
}