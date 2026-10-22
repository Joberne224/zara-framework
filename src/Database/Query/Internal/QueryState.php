<?php
declare(strict_types=1);

namespace Zara\Database\Query\Internal;

/**
 * The full state of a query under construction: which table, which columns,
 * which conditions, joins, grouping, ordering, and paging.
 *
 * Every QueryBuilder method that adds a clause produces a new QueryState via
 * one of the with*() methods below rather than mutating this one, mirroring
 * the immutable "with" idiom already used for HTTP messages elsewhere in
 * Zara. That means a QueryBuilder can be safely branched — e.g. building a
 * shared base query and then deriving two different filtered queries from it
 * — without one branch's where() leaking into the other's.
 */
final class QueryState
{
    /**
     * @param SelectExpression[] $columns
     * @param Condition[] $wheres
     * @param array<int, JoinClause|RawJoin> $joins
     * @param string[] $groups
     * @param Condition[] $havings
     * @param OrderByClause[] $orders
     */
    public function __construct(
        public readonly string $table,
        public readonly array $columns = [],
        public readonly array $wheres = [],
        public readonly array $joins = [],
        public readonly array $groups = [],
        public readonly array $havings = [],
        public readonly array $orders = [],
        public readonly ?int $limit = null,
        public readonly ?int $offset = null,
    ) {
    }

    /**
     * @param string[] $columns
     */
    public function withColumns(array $columns): self
    {
        return new self($this->table, $columns, $this->wheres, $this->joins, $this->groups, $this->havings, $this->orders, $this->limit, $this->offset);
    }

    /**
     * @param Condition[] $wheres
     */
    public function withWheres(array $wheres): self
    {
        return new self($this->table, $this->columns, $wheres, $this->joins, $this->groups, $this->havings, $this->orders, $this->limit, $this->offset);
    }

    /**
     * @param JoinClause[] $joins
     */
    public function withJoins(array $joins): self
    {
        return new self($this->table, $this->columns, $this->wheres, $joins, $this->groups, $this->havings, $this->orders, $this->limit, $this->offset);
    }

    /**
     * @param string[] $groups
     */
    public function withGroups(array $groups): self
    {
        return new self($this->table, $this->columns, $this->wheres, $this->joins, $groups, $this->havings, $this->orders, $this->limit, $this->offset);
    }

    /**
     * @param Condition[] $havings
     */
    public function withHavings(array $havings): self
    {
        return new self($this->table, $this->columns, $this->wheres, $this->joins, $this->groups, $havings, $this->orders, $this->limit, $this->offset);
    }

    /**
     * @param OrderByClause[] $orders
     */
    public function withOrders(array $orders): self
    {
        return new self($this->table, $this->columns, $this->wheres, $this->joins, $this->groups, $this->havings, $orders, $this->limit, $this->offset);
    }

    public function withLimit(?int $limit): self
    {
        return new self($this->table, $this->columns, $this->wheres, $this->joins, $this->groups, $this->havings, $this->orders, $limit, $this->offset);
    }

    public function withOffset(?int $offset): self
    {
        return new self($this->table, $this->columns, $this->wheres, $this->joins, $this->groups, $this->havings, $this->orders, $this->limit, $offset);
    }
}