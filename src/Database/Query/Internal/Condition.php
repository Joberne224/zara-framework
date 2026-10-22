<?php

declare(strict_types=1);

namespace Zara\Database\Query\Internal;

/**
 * An immutable WHERE/HAVING condition. Instances are only ever produced
 * through the named constructors below, each enforcing the invariants that
 * make sense at construction time (e.g. a valid operator for Basic).
 *
 * The "empty value list" invariant for In/NotIn is deliberately NOT enforced
 * here: an empty whereIn() is a compile-time concern, so ConditionCompiler is
 * the one that rejects it, with the column name and calling context already
 * in scope for a useful error message.
 */
final class Condition
{
    /**
     * @param mixed[] $values Bound parameter values, in the order they must
     *   be substituted for "?" placeholders. Empty for IS NULL / IS NOT NULL
     *   and for Group (a group's params live inside its nested conditions).
     * @param Condition[] $conditions Only populated for ConditionType::Group —
     *   the sub-conditions to be compiled and wrapped in parentheses.
     */
    private function __construct(
        public readonly ConditionType $type,
        public readonly BooleanOperator $boolean,
        public readonly ?string $column,
        public readonly ?string $operator,
        public readonly array $values,
        public readonly ?string $rawSql = null,
        public readonly array $conditions = [],
    ) {
    }

    public static function basic(string $column, string $operator, mixed $value, BooleanOperator $boolean): self
    {
        $context = "where()/having() on column \"{$column}\"";
        self::assertBindable($value, $context);
        self::assertNotNull($value, $context);

        return new self(ConditionType::Basic, $boolean, $column, ComparisonOperator::validate($operator), [$value]);
    }

    /**
     * @param mixed[] $values
     */
    public static function in(string $column, array $values, BooleanOperator $boolean): self
    {
        return new self(ConditionType::In, $boolean, $column, null, array_values($values));
    }

    /**
     * @param mixed[] $values
     */
    public static function notIn(string $column, array $values, BooleanOperator $boolean): self
    {
        return new self(ConditionType::NotIn, $boolean, $column, null, array_values($values));
    }

    public static function isNull(string $column, BooleanOperator $boolean): self
    {
        return new self(ConditionType::IsNull, $boolean, $column, null, []);
    }

    public static function isNotNull(string $column, BooleanOperator $boolean): self
    {
        return new self(ConditionType::IsNotNull, $boolean, $column, null, []);
    }

    public static function between(string $column, mixed $min, mixed $max, BooleanOperator $boolean): self
    {
        $context = "whereBetween() on column \"{$column}\"";
        self::assertBindable($min, $context);
        self::assertBindable($max, $context);
        self::assertNotNull($min, $context);
        self::assertNotNull($max, $context);

        return new self(ConditionType::Between, $boolean, $column, null, [$min, $max]);
    }

    /**
     * Arrays and objects cannot be bound to a "?" placeholder: PDO dies with
     * a low-level "Array to string conversion" (or an opaque driver error for
     * objects) far from the call site. Rejecting them here turns that into an
     * early, actionable error.
     */
    private static function assertBindable(mixed $value, string $context): void
    {
        if (is_array($value) || is_object($value)) {
            throw new \InvalidArgumentException(sprintf(
                '%s was given a %s as value. Only scalars and null can be bound.',
                $context,
                get_debug_type($value)
            ));
        }
    }

    /**
     * A bound NULL never matches anything: every comparison with NULL is
     * UNKNOWN in SQL's three-valued logic, so the condition would silently
     * reject every row. Nulls have explicit dedicated handling instead.
     */
    private static function assertNotNull(mixed $value, string $context): void
    {
        if ($value === null) {
            throw new \InvalidArgumentException(sprintf(
                '%s was given null. A bound NULL never matches (SQL three-valued logic), '
                    . 'so this condition would silently reject rows — use whereNull()/'
                    . 'whereNotNull() or validate the value before building the query.',
                $context
            ));
        }
    }

    /**
     * A parenthesized sub-group of conditions, e.g. the "(role = ? OR role
     * = ?)" in "active = ? AND (role = ? OR role = ?)". $conditions is
     * compiled recursively by ConditionCompiler, so a group can itself
     * contain another group.
     *
     * Unlike In/NotIn's empty-list check (deferred to ConditionCompiler),
     * an empty group is rejected right here: there is no calling-context
     * detail ConditionCompiler could add to that error that whereGroup()/
     * orWhereGroup() doesn't already have at the point of the call.
     *
     * @param Condition[] $conditions
     */
    public static function group(array $conditions, BooleanOperator $boolean): self
    {
        if ($conditions === []) {
            throw new \InvalidArgumentException(
                'whereGroup()/orWhereGroup() requires at least one condition inside the group.'
            );
        }

        return new self(ConditionType::Group, $boolean, null, null, [], null, $conditions);
    }

    /**
     * Escape hatch for anything the typed conditions above don't cover
     * (subqueries, vendor-specific functions, expressions on both sides...).
     * The caller owns injection safety for $sql; only $params are bound.
     *
     * @param mixed[] $params
     */
    public static function raw(string $sql, array $params, BooleanOperator $boolean): self
    {
        if (trim($sql) === '') {
            throw new \InvalidArgumentException('whereRaw()/havingRaw() requires a non-empty SQL fragment.');
        }

        return new self(ConditionType::Raw, $boolean, null, null, array_values($params), $sql);
    }
}