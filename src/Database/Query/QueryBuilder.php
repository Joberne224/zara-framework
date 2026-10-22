<?php
//File: zara-framework/src/Database/Query/QueryBuilder.php
declare(strict_types=1);

namespace Zara\Database\Query;

use Zara\Database\DatabaseInterface;
use Zara\Database\Query\Concerns\BuildsGroupingClauses;
use Zara\Database\Query\Concerns\BuildsJoinClauses;
use Zara\Database\Query\Concerns\BuildsOrderingClauses;
use Zara\Database\Query\Concerns\BuildsWhereClauses;
use Zara\Database\Query\Concerns\SelectsColumns;
use Zara\Database\Query\Internal\ConditionCompiler;
use Zara\Database\Query\Internal\JoinCompiler;
use Zara\Database\Query\Internal\QualifiedIdentifierQuoter;
use Zara\Database\Query\Internal\QueryState;
use Zara\Database\Query\Internal\SelectCompiler;
use Zara\Database\Query\Internal\SelectExpression;

/**
 * A fluent, immutable query builder sitting entirely on top of
 * DatabaseInterface. It never touches PDO, never builds a MySQL-specific
 * fragment, and never assumes a quoting character: every identifier is
 * quoted through $db->quoteIdentifier(), so the exact same QueryBuilder code
 * works unchanged against any DatabaseInterface implementation.
 *
 * Immutable: every clause method (select(), where(), join(), ...) returns a
 * NEW QueryBuilder rather than mutating the current one. This mirrors the
 * "with" idiom already used for HTTP messages elsewhere in Zara, and for the
 * same reason — a builder can be safely branched into two different queries
 * without one branch's where() call leaking into the other's.
 *
 * update() and delete() refuse to run without at least one where() clause,
 * throwing instead of silently affecting the whole table. Call updateAll()
 * or deleteAll() when that's genuinely what's intended — the point is to
 * make the dangerous case loud and the safe case the default.
 *
 * Clause-building methods are grouped by clause type into the traits under
 * Concerns\ (SelectsColumns, BuildsWhereClauses, BuildsJoinClauses,
 * BuildsGroupingClauses, BuildsOrderingClauses). This class itself only owns
 * construction, the shared QueryState, and the terminal methods below that
 * are the sole point of contact with DatabaseInterface — the same
 * "decompose into single-responsibility collaborators" pattern used
 * elsewhere in Zara (CacheManager, RouteCompiler, ServiceRegistry...).
 *
 * Usage:
 *
 *   $users = QueryBuilder::table($db, 'users')
 *       ->select('id', 'name', 'email')
 *       ->where('status', '=', 'active')
 *       ->whereIn('role', ['admin', 'editor'])
 *       ->orderBy('created_at', SortDirection::Desc)
 *       ->limit(20)
 *       ->get();
 *
 *   $activeCount = QueryBuilder::table($db, 'users')
 *       ->where('status', '=', 'active')
 *       ->count();
 *
 *   $countsPerRole = QueryBuilder::table($db, 'users')
 *       ->select('role')
 *       ->selectRaw('COUNT(*) AS total')
 *       ->groupBy('role')
 *       ->get();
 *
 *   QueryBuilder::table($db, 'users')
 *       ->where('id', '=', 42)
 *       ->update(['status' => 'inactive']);
 */
final class QueryBuilder
{
    use SelectsColumns;
    use BuildsWhereClauses;
    use BuildsJoinClauses;
    use BuildsGroupingClauses;
    use BuildsOrderingClauses;

    private function __construct(
        private readonly DatabaseInterface $db,
        private readonly QueryState $state,
        private readonly QualifiedIdentifierQuoter $identifiers,
        private readonly ConditionCompiler $conditionCompiler,
        private readonly SelectCompiler $selectCompiler,
    ) {
    }

    public static function table(DatabaseInterface $db, string $table): self
    {
        if (trim($table) === '') {
            throw new \InvalidArgumentException('Table name cannot be empty.');
        }

        // These collaborators are stateless aside from $db, which never
        // changes across a builder's fluent chain — built once here and
        // threaded through withState() rather than rebuilt on every
        // select()/where()/join() call.
        $identifiers = new QualifiedIdentifierQuoter($db);
        $conditionCompiler = new ConditionCompiler($identifiers);
        $selectCompiler = new SelectCompiler($identifiers, new JoinCompiler($identifiers), $conditionCompiler);

        return new self($db, new QueryState($table), $identifiers, $conditionCompiler, $selectCompiler);
    }

    private function withState(QueryState $state): self
    {
        return new self($this->db, $state, $this->identifiers, $this->conditionCompiler, $this->selectCompiler);
    }

    // ---------------------------------------------------------------
    // Terminal methods — these are the only points that touch DatabaseInterface
    // ---------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get(): array
    {
        $compiled = $this->selectCompiler->compile($this->state);

        return $this->db->query($compiled->sql, $compiled->params);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        $rows = $this->limit(1)->get();

        return $rows[0] ?? null;
    }

    public function exists(): bool
    {
        // Constant SELECT probe (SELECT 1 ... LIMIT 1) instead of fetching a
        // full row: existence needs neither the row's columns nor its payload.
        // Replacing any caller-selected columns keeps raw select expressions
        // (and their bound params) out of the probe.
        $compiled = $this->selectCompiler->compile(
            $this->state->withColumns([SelectExpression::raw('1')])->withLimit(1)
        );

        return $this->db->fetchOne($compiled->sql, $compiled->params) !== null;
    }

    /**
     * COUNT(*) (or COUNT($column)) over the current table/joins/where —
     * ignoring select()/orderBy()/limit()/offset(), which have no meaning
     * for an aggregate. Rejects a grouped state rather than silently
     * collapsing "one count per group" into a single misleading number.
     */
    public function count(string $column = '*'): int
    {
        if ($this->state->groups !== []) {
            throw new \LogicException(
                'count() does not support grouped queries (it would collapse ' .
                'one count per group into a single number). Use get() and ' .
                'aggregate the rows yourself instead — havingRaw() only filters ' .
                'the HAVING clause, it cannot put a COUNT expression in the ' .
                'SELECT list.'
            );
        }

        $compiled = $this->selectCompiler->compileCount($this->state, $column);

        return (int) $this->db->fetchColumn($compiled->sql, $compiled->params);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int|string
    {
        return $this->db->insert($this->state->table, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(array $data): int
    {
        if ($this->state->wheres === []) {
            throw new \LogicException(
                'update() called without any where() conditions, which would ' .
                'update every row in the table. Call updateAll() if that is ' .
                'really intended.'
            );
        }

        $where = $this->conditionCompiler->compile($this->state->wheres);

        return $this->db->update($this->state->table, $data, $where->sql, $where->params);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateAll(array $data): int
    {
        return $this->db->update($this->state->table, $data, '1 = 1');
    }

    public function delete(): int
    {
        if ($this->state->wheres === []) {
            throw new \LogicException(
                'delete() called without any where() conditions, which would ' .
                'delete every row in the table. Call deleteAll() if that is ' .
                'really intended.'
            );
        }

        $where = $this->conditionCompiler->compile($this->state->wheres);

        return $this->db->delete($this->state->table, $where->sql, $where->params);
    }

    public function deleteAll(): int
    {
        return $this->db->delete($this->state->table, '1 = 1');
    }

    // ---------------------------------------------------------------
    // Debugging
    // ---------------------------------------------------------------

    public function toSql(): string
    {
        return $this->selectCompiler->compile($this->state)->sql;
    }

    /**
     * @return mixed[]
     */
    public function getBindings(): array
    {
        return $this->selectCompiler->compile($this->state)->params;
    }
}