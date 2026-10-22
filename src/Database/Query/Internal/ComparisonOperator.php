<?php

declare(strict_types=1);

namespace Zara\Database\Query\Internal;

/**
 * Validates comparison operators used in WHERE/HAVING/JOIN ON clauses.
 *
 * Operators are interpolated directly into SQL text — there is no bound
 * placeholder for "the operator itself" the way there is for a value — so an
 * unvalidated operator string is a direct SQL injection vector the moment a
 * caller passes something other than a literal. This is the single place
 * that risk is closed, rather than trusting every call site (Condition,
 * JoinClause, ...) to remember to check it independently.
 */
final class ComparisonOperator
{
    private const ALLOWED = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'];

    public static function validate(string $operator): string
    {
        $normalized = strtoupper(trim($operator));

        if (!in_array($normalized, self::ALLOWED, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid comparison operator "%s". Allowed operators are: %s.',
                $operator,
                implode(', ', self::ALLOWED)
            ));
        }

        return $normalized;
    }

    private function __construct()
    {
        // Static-only helper, never instantiated.
    }
}