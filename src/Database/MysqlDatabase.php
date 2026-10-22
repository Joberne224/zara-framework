<?php
//File: zara-framework/src/Database/MysqlDatabase.php
declare(strict_types=1);

namespace Zara\Database;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Zara\Database\Internal\Mysql\ConnectionManager;
use Zara\Database\Internal\Mysql\IdentifierQuoter;
use Zara\Database\Internal\Mysql\StatementExecutor;
use Zara\Database\Internal\Shared\StatementCache;
use Zara\Database\Internal\Shared\TransactionManager;

/**
 * MySQL implementation of DatabaseInterface.
 *
 * All facade behavior now lives in AbstractSqlDatabase; this class only
 * builds MySQL's own engine-specific collaborators (ConnectionManager,
 * IdentifierQuoter, StatementExecutor — reconnect-on-dropped-connection via
 * MySQL error codes 2006/2013) and hands them, plus the collaborators
 * shared with every other driver (StatementCache, TransactionManager), to
 * the parent constructor.
 *
 * NOTE: these three collaborators live directly under Zara\Database\Internal
 * (no per-driver subnamespace) — that's the original, pre-existing location;
 * SqliteDatabase and PostgresDatabase, added later, keep their own
 * Internal\Sqlite / Internal\Postgres subnamespaces since those coexist with
 * classes of the same name.
 */
final class MysqlDatabase extends AbstractSqlDatabase
{
    /**
     * @param DatabaseConfig $config Database configuration
     * @param LoggerInterface|null $logger PSR-3 logger for debugging (defaults to NullLogger)
     * @param bool $logQueries Whether debug/error logs include the raw SQL text.
     *   Defaults to true (existing behavior). Table/column names in a query string
     *   can reveal schema details, so set this to false in deployments where logs
     *   leave the infrastructure (e.g. shipped to a third-party log aggregator)
     *   and that visibility isn't acceptable — bound parameter *values* are never
     *   logged either way, only their count.
     */
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
}