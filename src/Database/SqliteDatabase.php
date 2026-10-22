<?php
//File: zara-framework/src/Database/SqliteDatabase.php
declare(strict_types=1);

namespace Zara\Database;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Zara\Database\Internal\Shared\StatementCache;
use Zara\Database\Internal\Shared\TransactionManager;
use Zara\Database\Internal\Sqlite\ConnectionManager;
use Zara\Database\Internal\Sqlite\IdentifierQuoter;
use Zara\Database\Internal\Sqlite\StatementExecutor;

/**
 * SQLite implementation of DatabaseInterface.
 *
 * All facade behavior now lives in AbstractSqlDatabase; this class only
 * builds SQLite's own engine-specific collaborators (ConnectionManager,
 * IdentifierQuoter, StatementExecutor — no reconnect-on-lost-connection
 * logic, see StatementExecutor's doc comment) and hands them, plus the
 * collaborators shared with every other driver (StatementCache,
 * TransactionManager), to the parent constructor.
 */
final class SqliteDatabase extends AbstractSqlDatabase
{
    public function __construct(SqliteConfig $config, ?LoggerInterface $logger = null, bool $logQueries = true)
    {
        $logger ??= new NullLogger();
        $connections = new ConnectionManager($config);
        $statements = new StatementCache();
        $transactions = new TransactionManager($logger);
        $executor = new StatementExecutor($connections, $statements);
        $identifiers = new IdentifierQuoter();

        parent::__construct($connections, $statements, $transactions, $executor, $identifiers, $logger, $logQueries);
    }
}