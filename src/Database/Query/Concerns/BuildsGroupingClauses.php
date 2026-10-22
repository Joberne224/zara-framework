<?php

declare(strict_types=1);

namespace Zara\Database\Query\Concerns;

use Zara\Database\Query\Internal\BooleanOperator;
use Zara\Database\Query\Internal\Condition;

trait BuildsGroupingClauses
{
    public function groupBy(string ...$columns): self
    {
        return $this->withState($this->state->withGroups([...$this->state->groups, ...$columns]));
    }

    public function having(string $column, string $operator, mixed $value): self
    {
        return $this->addHaving(Condition::basic($column, $operator, $value, BooleanOperator::And));
    }

    public function orHaving(string $column, string $operator, mixed $value): self
    {
        return $this->addHaving(Condition::basic($column, $operator, $value, BooleanOperator::Or));
    }

    /**
     * HAVING almost always needs to compare an aggregate expression
     * (COUNT(*), SUM(amount)...), which isn't a plain identifier — so unlike
     * where(), having() is rarely what you want. Use havingRaw() for the
     * aggregate case; having() stays reserved for the plain-column case.
     *
     * @param mixed[] $params
     */
    public function havingRaw(string $sql, array $params = []): self
    {
        return $this->addHaving(Condition::raw($sql, $params, BooleanOperator::And));
    }

    private function addHaving(Condition $condition): self
    {
        return $this->withState($this->state->withHavings([...$this->state->havings, $condition]));
    }
}