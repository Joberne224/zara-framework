<?php

declare(strict_types=1);

namespace Zara\Database\Query\Concerns;

use Zara\Database\Query\Internal\JoinClause;
use Zara\Database\Query\Internal\JoinType;
use Zara\Database\Query\Internal\RawJoin;

trait BuildsJoinClauses
{
    public function join(string $table, string $first, string $operator, string $second): self
    {
        return $this->addJoin(JoinType::Inner, $table, $first, $operator, $second);
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->addJoin(JoinType::Left, $table, $first, $operator, $second);
    }

    public function rightJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->addJoin(JoinType::Right, $table, $first, $operator, $second);
    }

    /**
     * Escape hatch for joins the typed join()/leftJoin()/rightJoin() cannot
     * express (composite ON conditions, table aliases, CROSS JOIN...). $sql
     * is trusted as-is — the caller owns injection safety for it — only
     * $params are bound. The fragment must include its own JOIN keyword and
     * ON clause, e.g. joinRaw('LEFT JOIN orders o ON o.user_id = users.id').
     *
     * @param mixed[] $params
     */
    public function joinRaw(string $sql, array $params = []): self
    {
        return $this->withState($this->state->withJoins([
            ...$this->state->joins,
            new RawJoin($sql, array_values($params)),
        ]));
    }

    private function addJoin(JoinType $type, string $table, string $first, string $operator, string $second): self
    {
        $join = new JoinClause($type, $table, $first, $operator, $second);

        return $this->withState($this->state->withJoins([...$this->state->joins, $join]));
    }
}
