<?php

declare(strict_types=1);

namespace Zara\Database\Query\Concerns;

use Zara\Database\Query\Internal\OrderByClause;
use Zara\Database\Query\Internal\SortDirection;

trait BuildsOrderingClauses
{
    public function orderBy(string $column, SortDirection $direction = SortDirection::Asc): self
    {
        $order = new OrderByClause($column, $direction);

        return $this->withState($this->state->withOrders([...$this->state->orders, $order]));
    }

    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException('limit() must be >= 0.');
        }

        return $this->withState($this->state->withLimit($limit));
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException('offset() must be >= 0.');
        }

        return $this->withState($this->state->withOffset($offset));
    }
}