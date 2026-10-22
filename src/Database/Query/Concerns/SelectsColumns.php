<?php

declare(strict_types=1);

namespace Zara\Database\Query\Concerns;

use Zara\Database\Query\Internal\SelectExpression;

/**
 * select() — restricts (or resets) the list of columns returned by get().
 * selectRaw() — appends a raw SQL fragment to that list, for expressions the
 * typed select() cannot express (aggregates, CASE WHEN, table aliases).
 *
 * Both live in their own concern for symmetry with the other clause-building
 * traits below; QueryBuilder itself is just a composition of these plus the
 * terminal methods that actually touch DatabaseInterface.
 */
trait SelectsColumns
{
    public function select(string ...$columns): self
    {
        return $this->withState($this->state->withColumns(array_map(
            SelectExpression::column(...),
            $columns
        )));
    }

    /**
     * Appends a raw SQL fragment to the SELECT list — the escape hatch for
     * expressions the typed select() cannot express. $sql is trusted as-is —
     * the caller owns injection safety for it — only $params are bound.
     * Unlike select(), which replaces the whole list, selectRaw() appends, so
     * it composes with select() and groupBy() for per-group aggregates:
     *
     *   ->select('role')
     *   ->selectRaw('COUNT(*) AS total')
     *   ->groupBy('role')
     *
     * @param mixed[] $params
     */
    public function selectRaw(string $sql, array $params = []): self
    {
        return $this->withState($this->state->withColumns([
            ...$this->state->columns,
            SelectExpression::raw($sql, $params),
        ]));
    }
}
