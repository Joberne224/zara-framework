<?php
//File: zara-framework/src/Database/Query/WhereGroupBuilder.php
declare(strict_types=1);

namespace Zara\Database\Query;

use Zara\Database\Query\Internal\BooleanOperator;
use Zara\Database\Query\Internal\Condition;

/**
 * A minimal, immutable where()-clause collector passed into whereGroup()/
 * orWhereGroup() callbacks. Mirrors QueryBuilder's own where*() methods and
 * "with" idiom exactly (every method returns a NEW instance) — the callback
 * MUST return the instance it ends up with, since nothing is mutated behind
 * its back. This keeps the exact same immutability guarantee QueryBuilder
 * gives its callers, just scoped to a group's contents.
 *
 * Deliberately NOT a QueryBuilder itself: a group has no table, no joins, no
 * ordering — only conditions — so exposing QueryBuilder's full surface here
 * would let a callback call things like ->limit() that make no sense inside
 * a WHERE group and would silently do nothing.
 *
 * Usage:
 *
 *   QueryBuilder::table($db, 'users')
 *       ->where('active', '=', true)
 *       ->whereGroup(fn (WhereGroupBuilder $group) => $group
 *           ->where('role', '=', 'admin')
 *           ->orWhere('role', '=', 'editor'))
 *       ->get();
 *
 * compiles to "active = ? AND (role = ? OR role = ?)".
 */
final class WhereGroupBuilder
{
    /**
     * @param Condition[] $conditions
     */
    private function __construct(private readonly array $conditions = [])
    {
    }

    public static function empty(): self
    {
        return new self();
    }

    public function where(string $column, string $operator, mixed $value): self
    {
        return $this->add(Condition::basic($column, $operator, $value, BooleanOperator::And));
    }

    public function orWhere(string $column, string $operator, mixed $value): self
    {
        return $this->add(Condition::basic($column, $operator, $value, BooleanOperator::Or));
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
        return $this->add(Condition::in($column, $values, BooleanOperator::And));
    }

    /**
     * @param mixed[] $values
     */
    public function whereNotIn(string $column, array $values): self
    {
        return $this->add(Condition::notIn($column, $values, BooleanOperator::And));
    }

    public function whereNull(string $column): self
    {
        return $this->add(Condition::isNull($column, BooleanOperator::And));
    }

    public function whereNotNull(string $column): self
    {
        return $this->add(Condition::isNotNull($column, BooleanOperator::And));
    }

    public function whereBetween(string $column, mixed $min, mixed $max): self
    {
        return $this->add(Condition::between($column, $min, $max, BooleanOperator::And));
    }

    /**
     * Escape hatch for conditions the typed methods above don't cover.
     * $sql is trusted as-is — the caller owns injection safety for it —
     * only $params are bound.
     *
     * @param mixed[] $params
     */
    public function whereRaw(string $sql, array $params = []): self
    {
        return $this->add(Condition::raw($sql, $params, BooleanOperator::And));
    }

    /**
     * @return Condition[]
     */
    public function conditions(): array
    {
        return $this->conditions;
    }

    private function add(Condition $condition): self
    {
        return new self([...$this->conditions, $condition]);
    }
}