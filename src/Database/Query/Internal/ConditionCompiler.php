<?php

declare(strict_types=1);

namespace Zara\Database\Query\Internal;

/**
 * Turns an ordered list of Condition value objects into a single SQL
 * fragment (no leading "WHERE"/"HAVING" keyword — that's the caller's job,
 * since the same fragment shape is also what DatabaseInterface::update() and
 * ::delete() expect for their $where parameter) plus the flat, ordered list
 * of bound parameters to go with it.
 *
 * No parentheses are ever inserted around individual conditions: they are
 * joined flat with AND/OR in call order. Because SQL binds AND tighter than
 * OR, a chain like where('a', ...)->orWhere('b', ...)->where('c', ...)
 * compiles to "a = ? OR b = ? AND c = ?", which evaluates as
 * "a OR (b AND c)" rather than "(a OR b) AND c". Explicit grouping is
 * available via whereGroup()/orWhereGroup() (ConditionType::Group below),
 * which wraps its nested conditions in parentheses; whereRaw()/havingRaw()
 * remain the fallback for anything grouping still doesn't cover.
 */
final class ConditionCompiler
{
    /**
     * Above this size, a single IN (...) clause risks hitting the driver's
     * placeholder limit (65,535 on MySQL/PostgreSQL, 32,766 on recent
     * SQLite) or degrading badly before that point. Failing loudly here
     * turns a late, cryptic driver-level error (or a silent slowdown) into
     * an early, actionable one: the caller must batch the ids itself
     * (e.g. via array_chunk()) rather than pass an unbounded list through.
     */
    private const MAX_IN_LIST_SIZE = 1000;

    public function __construct(private readonly QualifiedIdentifierQuoter $identifiers)
    {
    }

    /**
     * @param Condition[] $conditions
     */
    public function compile(array $conditions): CompiledQuery
    {
        if ($conditions === []) {
            return new CompiledQuery('', []);
        }

        $fragments = [];
        $params = [];

        foreach ($conditions as $index => $condition) {
            $prefix = $index === 0 ? '' : $condition->boolean->value . ' ';
            [$fragment, $conditionParams] = $this->compileOne($condition);
            $fragments[] = $prefix . $fragment;
            array_push($params, ...$conditionParams);
        }

        return new CompiledQuery(implode(' ', $fragments), $params);
    }

    /**
     * @return array{0: string, 1: mixed[]}
     */
    private function compileOne(Condition $condition): array
    {
        return match ($condition->type) {
            ConditionType::Basic => [
                $this->identifiers->quote((string) $condition->column) . ' ' . $condition->operator . ' ?',
                [$condition->values[0]],
            ],
            ConditionType::In => $this->compileInList($condition, negated: false),
            ConditionType::NotIn => $this->compileInList($condition, negated: true),
            ConditionType::IsNull => [
                $this->identifiers->quote((string) $condition->column) . ' IS NULL',
                [],
            ],
            ConditionType::IsNotNull => [
                $this->identifiers->quote((string) $condition->column) . ' IS NOT NULL',
                [],
            ],
            ConditionType::Between => [
                $this->identifiers->quote((string) $condition->column) . ' BETWEEN ? AND ?',
                $condition->values,
            ],
            ConditionType::Raw => [
                (string) $condition->rawSql,
                $condition->values,
            ],
            ConditionType::Group => $this->compileGroup($condition),
        };
    }

    /**
     * Recurses through compile() so a group can itself contain another
     * group — nothing here is specific to one nesting level.
     *
     * @return array{0: string, 1: mixed[]}
     */
    private function compileGroup(Condition $condition): array
    {
        $compiled = $this->compile($condition->conditions);

        return ['(' . $compiled->sql . ')', $compiled->params];
    }

    /**
     * @return array{0: string, 1: mixed[]}
     */
    private function compileInList(Condition $condition, bool $negated): array
    {
        if ($condition->values === []) {
            // Empty IN() is invalid SQL, and a classic silent-failure trap: a
            // naive implementation either emits `IN ()` (syntax error) or
            // silently matches/rejects every row depending on negation.
            // Failing loudly here, with the column name in the message, beats
            // either of those.
            throw new \InvalidArgumentException(sprintf(
                'whereIn()/whereNotIn() on column "%s" was given an empty value list.',
                $condition->column
            ));
        }

        if (count($condition->values) > self::MAX_IN_LIST_SIZE) {
            throw new \InvalidArgumentException(sprintf(
                'whereIn()/whereNotIn() on column "%s" was given %d values, above the %d-value safety limit. '
                    . 'Split the list into batches (e.g. array_chunk()) and issue one query per batch.',
                $condition->column,
                count($condition->values),
                self::MAX_IN_LIST_SIZE,
            ));
        }

        foreach ($condition->values as $value) {
            if ($value === null || is_array($value) || is_object($value)) {
                throw new \InvalidArgumentException(sprintf(
                    'whereIn()/whereNotIn() on column "%s" was given a %s in its value list. '
                        . 'Only scalars can be bound: null never matches inside IN () (SQL '
                        . 'three-valued logic — handle it with whereNull()/whereNotNull() '
                        . 'alongside instead), and arrays/objects cannot be bound at all.',
                    $condition->column,
                    get_debug_type($value)
                ));
            }
        }

        $placeholders = implode(', ', array_fill(0, count($condition->values), '?'));
        $keyword = $negated ? 'NOT IN' : 'IN';

        return [
            $this->identifiers->quote((string) $condition->column) . " {$keyword} ({$placeholders})",
            $condition->values,
        ];
    }
}