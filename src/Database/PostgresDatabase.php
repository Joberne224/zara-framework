<?php
//File: zara-framework/src/Database/PostgresDatabase.php
declare(strict_types=1);

namespace Zara\Database;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Zara\Database\Exceptions\QueryException;
use Zara\Database\Internal\Postgres\ConnectionManager;
use Zara\Database\Internal\Postgres\IdentifierQuoter;
use Zara\Database\Internal\Postgres\StatementExecutor;
use Zara\Database\Internal\Shared\StatementCache;
use Zara\Database\Internal\Shared\TransactionManager;

/**
 * PostgreSQL implementation of DatabaseInterface.
 *
 * All facade behavior now lives in AbstractSqlDatabase; this class only
 * builds PostgreSQL's own engine-specific collaborators (ConnectionManager,
 * IdentifierQuoter, StatementExecutor) and hands them, plus the
 * collaborators shared with every other driver (StatementCache,
 * TransactionManager), to the parent constructor.
 *
 * Reuses DatabaseConfig as-is (host/port/dbname/username/password/charset
 * all apply to PostgreSQL) — pass port: 5432; DatabaseConfig's default port
 * (3306) is MySQL's and is NOT translated. Its default charset ('utf8mb4')
 * IS translated to 'UTF8' by the connection manager, since it is a pure
 * MySQL alias of the same encoding.
 *
 * lastInsertId()/insert() resolve the generated id through SELECT lastval()
 * rather than PDO's lastInsertId(): PDO_PGSQL without an explicit sequence
 * name reports the insert OID (0 on modern Postgres tables), which is not
 * the id callers expect. lastval() is the MySQL-equivalent — the value of
 * the most recent sequence consumed in this session — without requiring the
 * sequence's name. An explicit $sequenceName still uses PDO's currval-based
 * path.
 */
final class PostgresDatabase extends AbstractSqlDatabase
{
    public function __construct(DatabaseConfig $config, ?LoggerInterface $logger = null, bool $logQueries = true)
    {
        $logger ??= new NullLogger();
        $connections = new ConnectionManager($config, $logger);
        $statements = new StatementCache();
        $transactions = new TransactionManager($logger);
        $executor = new StatementExecutor($connections, $statements, $transactions, $logger);
        $identifiers = new IdentifierQuoter($logger);

        parent::__construct($connections, $statements, $transactions, $executor, $identifiers, $logger, $logQueries);
    }

    protected function fetchLastInsertId(?string $sequenceName = null): string
    {
        if ($sequenceName !== null) {
            return parent::fetchLastInsertId($sequenceName);
        }

        try {
            return (string) $this->fetchColumn('SELECT lastval()');
        } catch (QueryException $e) {
            // lastval() errors with "lastval is not yet defined in this session"
            // when no sequence has been used yet — e.g. the insert targeted a
            // table without a BIGSERIAL column. That is the same case where
            // MySQL's lastInsertId() answers 0 rather than failing, so mirror
            // it instead of surfacing an error after a successful insert.
            $previous = $e->getPrevious();
            if ($previous === null || !str_contains($previous->getMessage(), 'lastval')) {
                throw $e;
            }

            return '0';
        }
    }
}
