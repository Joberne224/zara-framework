<?php

declare(strict_types=1);

namespace Zara\Database\Query\Internal;

/**
 * A single "<TYPE> JOIN <table> ON <first> <operator> <second>" clause.
 *
 * $first and $second are column references (optionally "table.column"),
 * never bound values. A join condition that needs to compare a column
 * against a literal belongs in a where() call on the query being built, not
 * in the join itself.
 *
 * Only a single ON condition is supported — a composite join condition
 * ("ON a.x = b.x AND a.y = b.y") or a table alias isn't expressible through
 * this clause; use QueryBuilder::joinRaw() for those.
 */
final class JoinClause
{
    public readonly string $operator;

    public function __construct(
        public readonly JoinType $type,
        public readonly string $table,
        public readonly string $first,
        string $operator,
        public readonly string $second,
    ) {
        $this->operator = ComparisonOperator::validate($operator);
    }
}