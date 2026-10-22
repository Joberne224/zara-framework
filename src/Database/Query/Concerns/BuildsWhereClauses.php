<?php

declare(strict_types=1);

namespace Zara\Database\Query\Concerns;

use Zara\Database\Query\Internal\BooleanOperator;
use Zara\Database\Query\Internal\Condition;
use Zara\Database\Query\WhereGroupBuilder;

/**
 * Every where*() method QueryBuilder exposes. All of them fall through to
 * addWhere(), which appends a Condition onto the current QueryState and
 * returns the resulting new builder — the same immutable "with" idiom
 * every other clause group in this namespace follows.
 */
trait BuildsWhereClauses
{
    public function where(string $column, string $operator, mixed $value): self
    {
        return $this->addWhere(Condition::basic($column, $operator, $value, BooleanOperator::And));
    }

    public function orWhere(string $column, string $operator, mixed $value): self
    {
        return $this->addWhere(Condition::basic($column, $operator, $value, BooleanOperator::Or));
    }

    /**
     * Sugar for the common where($column, '=', $value) case.
     */
    public function whereEquals(string $column, mixed $value): self
    {
        return $this->where($column, '=', $value);
    }

    /**
     * @param mixed[] $values
     */
    public function whereIn(string $column, array $values): self
    {
        return $this->addWhere(Condition::in($column, $values, BooleanOperator::And));
    }

    /**
     * @param mixed[] $values
     */
    public function whereNotIn(string $column, array $values): self
    {
        return $this->addWhere(Condition::notIn($column, $values, BooleanOperator::And));
    }

    public function whereNull(string $column): self
    {
        return $this->addWhere(Condition::isNull($column, BooleanOperator::And));
    }

    public function whereNotNull(string $column): self
    {
        return $this->addWhere(Condition::isNotNull($column, BooleanOperator::And));
    }

    public function whereBetween(string $column, mixed $min, mixed $max): self
    {
        return $this->addWhere(Condition::between($column, $min, $max, BooleanOperator::And));
    }

    /**
     * Escape hatch for conditions the typed methods above don't cover
     * (subqueries, vendor-specific expressions...). $sql is trusted as-is —
     * the caller owns injection safety for it — only $params are bound.
     *
     * @param mixed[] $params
     */
    public function whereRaw(string $sql, array $params = []): self
    {
        return $this->addWhere(Condition::raw($sql, $params, BooleanOperator::And));
    }

    /**
     * Groups a set of conditions inside parentheses, connected to the rest
     * of the WHERE clause with AND. Use this whenever mixing where()/
     * orWhere() alone would produce ambiguous precedence — see
     * ConditionCompiler's class doc comment for why that ambiguity exists.
     *
     * The callback receives an empty WhereGroupBuilder and MUST return the
     * instance it ends up building (WhereGroupBuilder is immutable, just
     * like QueryBuilder itself — see its class doc comment).
     *
     * Example:
     *   ->where('active', '=', true)
     *   ->whereGroup(fn (WhereGroupBuilder $g) => $g
     *       ->where('role', '=', 'admin')
     *       ->orWhere('role', '=', 'editor'))
     * compiles to "active = ? AND (role = ? OR role = ?)".
     */
    public function whereGroup(callable $callback): self
    {
        return $this->addWhereGroup($callback, BooleanOperator::And);
    }

    /**
     * Same as whereGroup(), but connected to the rest of the WHERE clause
     * with OR instead of AND.
     */
    public function orWhereGroup(callable $callback): self
    {
        return $this->addWhereGroup($callback, BooleanOperator::Or);
    }

    private function addWhereGroup(callable $callback, BooleanOperator $boolean): self
    {
        $result = $callback(WhereGroupBuilder::empty());

        if (!$result instanceof WhereGroupBuilder) {
            throw new \LogicException(
                'whereGroup()/orWhereGroup() callback must return the WhereGroupBuilder ' .
                'it received (e.g. "return $group->where(...)->orWhere(...);") — its ' .
                'return value becomes the group; nothing is mutated implicitly.'
            );
        }

        return $this->addWhere(Condition::group($result->conditions(), $boolean));
    }

    private function addWhere(Condition $condition): self
    {
        return $this->withState($this->state->withWheres([...$this->state->wheres, $condition]));
    }
}