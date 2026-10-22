<?php

declare(strict_types=1);

namespace Zara\Database\Schema;

use Zara\Database\DatabaseInterface;

/**
 * Single entry point of the schema builder. The injected grammar decides the
 * SQL dialect (Mysql/Postgres/Sqlite); this facade knows nothing about
 * dialects, the same principle as DatabaseInterface towards its concrete
 * implementations.
 */
final class Schema
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly SchemaGrammarInterface $grammar,
    ) {
    }

    public function create(string $table, callable $callback): void
    {
        $this->runAtomically($this->compileCreate($table, $callback, ifNotExists: false));
    }

    public function createIfNotExists(string $table, callable $callback): void
    {
        $this->runAtomically($this->compileCreate($table, $callback, ifNotExists: true));
    }

    /**
     * Alters an existing table (add/drop columns, indexes, foreign keys),
     * the equivalent of ALTER TABLE.
     */
    public function table(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        $this->runAtomically($this->grammar->compileAlter($blueprint));
    }

    public function drop(string $table): void
    {
        $this->db->execute($this->grammar->compileDrop($table));
    }

    public function dropIfExists(string $table): void
    {
        $this->db->execute($this->grammar->compileDropIfExists($table));
    }

    /**
     * Whether the active driver can roll back DDL statements.
     */
    public function supportsTransactionalDdl(): bool
    {
        return $this->grammar->supportsTransactionalDdl();
    }

    /**
     * @return list<string>
     */
    private function compileCreate(string $table, callable $callback, bool $ifNotExists): array
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        $this->assertHasColumns($blueprint);

        return $this->grammar->compileCreate($blueprint, $ifNotExists);
    }

    /**
     * Runs a compiled statement list as one unit when the driver can roll
     * back DDL (PostgreSQL, SQLite): a failure in the second statement (e.g.
     * a CREATE INDEX after the CREATE TABLE) must not leave a half-built
     * table behind. MySQL commits implicitly on every DDL statement, so the
     * statements run bare there — wrapping them in a transaction would have
     * it closed behind our back and the final commit() would fail.
     *
     * A caller already inside a transaction is fine: transaction() nests
     * through savepoints.
     *
     * @param list<string> $statements
     */
    private function runAtomically(array $statements): void
    {
        if ($statements === []) {
            return;
        }

        if (!$this->grammar->supportsTransactionalDdl()) {
            foreach ($statements as $statement) {
                $this->db->execute($statement);
            }

            return;
        }

        $this->db->transaction(function () use ($statements): void {
            foreach ($statements as $statement) {
                $this->db->execute($statement);
            }
        });
    }

    private function assertHasColumns(Blueprint $blueprint): void
    {
        if ($blueprint->getColumns() === []) {
            throw new \InvalidArgumentException('A table must define at least one column.');
        }
    }
}
