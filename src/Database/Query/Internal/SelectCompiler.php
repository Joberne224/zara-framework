<?php

declare(strict_types=1);

namespace Zara\Database\Query\Internal;

/**
 * Assembles the full SELECT statement from a QueryState, delegating each
 * clause to the collaborator that owns it: JoinCompiler for JOINs,
 * ConditionCompiler for WHERE/HAVING. This class only owns the ordering and
 * assembly of clauses, never the SQL syntax of any one of them.
 */
final class SelectCompiler
{
    public function __construct(
        private readonly QualifiedIdentifierQuoter $identifiers,
        private readonly JoinCompiler $joins,
        private readonly ConditionCompiler $conditions,
    ) {
    }

    public function compile(QueryState $state): CompiledQuery
    {
        $base = $this->compileFromJoinWhere($state);

        $fragments = [];
        $selectParams = [];

        foreach ($state->columns as $expression) {
            if ($expression->raw) {
                $fragments[] = $expression->sql;
                array_push($selectParams, ...$expression->params);
            } else {
                $fragments[] = $this->quoteSelectColumn($expression->sql);
            }
        }

        $columns = $fragments === [] ? '*' : implode(', ', $fragments);

        $sql = "SELECT {$columns} " . $base->sql;
        // Placeholder order must mirror SQL order: SELECT-list params come
        // before the FROM/JOIN/WHERE params collected in $base.
        $params = [...$selectParams, ...$base->params];

        if ($state->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', array_map(
                fn (string $column): string => $this->identifiers->quote($column),
                $state->groups
            ));
        }

        if ($state->havings !== []) {
            $having = $this->conditions->compile($state->havings);
            $sql .= ' HAVING ' . $having->sql;
            array_push($params, ...$having->params);
        }

        if ($state->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', array_map(
                fn (OrderByClause $order): string => $this->identifiers->quote($order->column) . ' ' . $order->direction->value,
                $state->orders
            ));
        }

        if ($state->limit !== null) {
            $sql .= ' LIMIT ' . $state->limit;
        }

        if ($state->offset !== null) {
            if ($state->limit === null) {
                // Most engines (MySQL included) require LIMIT to use OFFSET.
                // Failing loudly here beats silently emitting SQL that
                // behaves as if offset() was never called.
                throw new \LogicException('offset() requires limit() to also be set.');
            }
            $sql .= ' OFFSET ' . $state->offset;
        }

        return new CompiledQuery($sql, $params);
    }

    /**
     * Builds "SELECT <aggregateExpression> FROM ... [JOIN ...] [WHERE ...]"
     * — deliberately ignoring columns/GROUP BY/ORDER BY/LIMIT/OFFSET, since
     * an aggregate query has no use for any of them. Grouped aggregates
     * (COUNT per group) are out of scope here; QueryBuilder::count() rejects
     * a state with groups rather than silently returning a single number
     * where the caller expected one per group.
     */
    public function compileAggregate(QueryState $state, string $aggregateExpression): CompiledQuery
    {
        $base = $this->compileFromJoinWhere($state);

        $sql = "SELECT {$aggregateExpression} " . $base->sql;
        $params = $base->params;

        if ($state->havings !== []) {
            // GROUP BY itself is out of scope here (QueryBuilder::count()
            // rejects a grouped state before ever reaching this method), so
            // the only case left is HAVING used as a whole-table filter
            // without GROUP BY — valid SQL, and previously dropped silently.
            $having = $this->conditions->compile($state->havings);
            $sql .= ' HAVING ' . $having->sql;
            array_push($params, ...$having->params);
        }

        return new CompiledQuery($sql, $params);
    }

    public function compileCount(QueryState $state, string $column = '*'): CompiledQuery
    {
        $expression = $column === '*'
            ? 'COUNT(*)'
            : 'COUNT(' . $this->identifiers->quote($column) . ')';

        return $this->compileAggregate($state, $expression);
    }

    /**
     * The part every SELECT-family query shares: "FROM <table> [JOIN ...]
     * [WHERE ...]". Factored out so compile() and compileAggregate() can't
     * drift apart on how FROM/JOIN/WHERE get built.
     */
    private function compileFromJoinWhere(QueryState $state): CompiledQuery
    {
        $sql = 'FROM ' . $this->identifiers->quote($state->table);
        $params = [];

        if ($state->joins !== []) {
            $joins = $this->joins->compile($state->joins);
            $sql .= ' ' . $joins->sql;
            array_push($params, ...$joins->params);
        }

        if ($state->wheres !== []) {
            $where = $this->conditions->compile($state->wheres);
            $sql .= ' WHERE ' . $where->sql;
            array_push($params, ...$where->params);
        }

        return new CompiledQuery($sql, $params);
    }

    /**
     * Unlike QualifiedIdentifierQuoter::quote(), the SELECT column list is
     * the only place where an "AS" alias makes sense. It is split explicitly
     * here instead of teaching quote() to parse expressions, which would be
     * outside its documented scope.
     */
    private function quoteSelectColumn(string $column): string
    {
        if (preg_match('/^(.+?)\s+AS\s+(.+)$/i', trim($column), $matches) === 1) {
            return $this->identifiers->quote(trim($matches[1])) . ' AS ' . $this->identifiers->quote(trim($matches[2]));
        }

        return $this->identifiers->quote($column);
    }
}
