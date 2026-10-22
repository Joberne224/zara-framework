<?php
//File: zara-framework/src/Database/DatabaseInterface.php
declare(strict_types=1);

namespace Zara\Database;

/**
 * Interface for database operations.
 * This interface provides a contract for database interactions,
 * supporting MySQL and potentially other database systems.
 */
interface DatabaseInterface
{
    /**
     * Establish a connection to the database.
     * @throws ConnectionException If connection fails
     */
    public function connect(): void;

    /**
     * Execute a SELECT query and return all results as associative arrays.
     * @param string $query The SQL query
     * @param array $params Query parameters (for prepared statements)
     * @return array Associative array of results (empty array if no results)
     * @throws QueryException If query execution fails
     */
    public function query(string $query, array $params = []): array;

    /**
     * Execute a query (INSERT/UPDATE/DELETE) and return the number of affected rows.
     * Note: For SELECT queries, rowCount() may not be reliable.
     * @param string $query The SQL query
     * @param array $params Query parameters (for prepared statements)
     * @return int Number of affected rows
     * @throws QueryException If query execution fails
     */
    public function execute(string $query, array $params = []): int;

    /**
     * Insert a row into the specified table.
     * @param string $table Table name (must be a valid identifier)
     * @param array $data Associative array of column => value pairs
     * @return int|string The last insert ID (int for MySQL, string for large IDs)
     * @throws QueryException If insert fails
     * @throws InvalidIdentifierException If table name is invalid
     * @throws \InvalidArgumentException If $data is empty
     */
    public function insert(string $table, array $data): int|string;

    /**
     * Update rows in the specified table matching a WHERE condition.
     * @param string $table Table name (must be a valid identifier)
     * @param array $data Associative array of column => value pairs to set
     * @param string $where Raw WHERE clause (without the "WHERE" keyword), using "?"
     *   placeholders for any values — e.g. "id = ?" or "status = ? AND created_at < ?".
     *   This is not parsed or validated: callers must never interpolate untrusted
     *   values directly into $where, only via placeholders bound through $whereParams.
     * @param array $whereParams Parameters bound to the "?" placeholders in $where, in order
     * @return int Number of affected rows
     * @throws QueryException If the update fails
     * @throws InvalidIdentifierException If table or column name is invalid
     * @throws \InvalidArgumentException If $data is empty
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int;

    /**
     * Delete rows from the specified table matching a WHERE condition.
     * @param string $table Table name (must be a valid identifier)
     * @param string $where Raw WHERE clause (without the "WHERE" keyword), using "?"
     *   placeholders for any values — see {@see update()} for the same caveat about
     *   never interpolating untrusted values directly into $where.
     * @param array $whereParams Parameters bound to the "?" placeholders in $where, in order
     * @return int Number of affected rows
     * @throws QueryException If the delete fails
     * @throws InvalidIdentifierException If table name is invalid
     */
    public function delete(string $table, string $where, array $whereParams = []): int;

    /**
     * Execute a SELECT query and return a single scalar value from the first row.
     * Convenient for aggregates (COUNT, SUM, EXISTS, ...) or single-column lookups
     * where fetchOne() plus manual array access would be overkill.
     * @param string $query The SQL query
     * @param array $params Query parameters (for prepared statements)
     * @param int $column Zero-based column index to read from the first row
     * @return mixed The scalar value, or null if the query returned no rows
     * @throws QueryException If query execution fails
     */
    public function fetchColumn(string $query, array $params = [], int $column = 0): mixed;

    /**
     * Execute a SELECT query and return the first result.
     * @param string $query The SQL query
     * @param array $params Query parameters (for prepared statements)
     * @return array|null Associative array of the first result, or null if no results
     * @throws QueryException If query execution fails
     */
    public function fetchOne(string $query, array $params = []): ?array;

    /**
     * Start a transaction. Calling this while a transaction is already open starts a
     * nested savepoint instead of failing, so a method using transaction() can safely
     * call another method that also uses transaction() without needing to know
     * whether it's already inside one.
     * @throws TransactionException If transaction cannot be started
     */
    public function beginTransaction(): void;

    /**
     * Commit the current transaction (or release the current savepoint, if nested).
     * @throws TransactionException If no transaction is active or commit fails
     */
    public function commit(): void;

    /**
     * Roll back the current transaction (or roll back to the current savepoint, if nested).
     * @throws TransactionException If no transaction is active or rollback fails
     */
    public function rollback(): void;

    /**
     * Get the last insert ID.
     * @param string|null $sequenceName Optional sequence name (for databases that support it)
     * @return int|string The last insert ID
     */
    public function lastInsertId(?string $sequenceName = null): int|string;

    /**
     * Disconnect from the database.
     */
    public function disconnect(): void;

    /**
     * Check if the database connection is active.
     * @return bool True if connected, false otherwise
     */
    public function isConnected(): bool;

    /**
     * Execute a callback within a transaction.
     * Automatically commits on success, rolls back on exception.
     * Safe to call from within another transaction() call (or manual
     * beginTransaction()): nested invocations use a savepoint and only the
     * outermost call actually commits/rolls back the real transaction.
     * The callback receives this DatabaseInterface instance as its argument, so it
     * doesn't need to capture one from the enclosing scope (a callback declared
     * with zero parameters still works — PHP ignores the extra argument).
     * @template T
     * @param callable(DatabaseInterface): T $callback The callback to execute
     * @return T The callback return value
     * @throws TransactionException If transaction fails
     * @throws \Throwable If the callback throws an exception
     */
    public function transaction(callable $callback): mixed;

    /**
     * Quote a SQL identifier (table name, column name, etc.) to prevent SQL injection.
     * @param string $identifier The identifier to quote
     * @return string The quoted identifier
     * @throws InvalidIdentifierException If identifier is invalid
     */
    public function quoteIdentifier(string $identifier): string;
}