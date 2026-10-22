<?php
// Zara/Database/Schema/SchemaGrammarFactory.php
declare(strict_types=1);

namespace Zara\Database\Schema;

use Zara\Database\DatabaseInterface;
use Zara\Database\MysqlDatabase;
use Zara\Database\PostgresDatabase;
use Zara\Database\Schema\Grammar\Mysql\MysqlGrammar;
use Zara\Database\Schema\Grammar\Postgres\PostgresGrammar;
use Zara\Database\Schema\Grammar\Sqlite\SqliteGrammar;
use Zara\Database\SqliteDatabase;

/**
 * Picks the SQL grammar that matches the database implementation,
 * so a Schema can never be built with the wrong dialect.
 */
final class SchemaGrammarFactory
{
    public static function forDatabase(DatabaseInterface $database): SchemaGrammarInterface
    {
        return match (true) {
            $database instanceof MysqlDatabase    => new MysqlGrammar(),
            $database instanceof PostgresDatabase => new PostgresGrammar(),
            $database instanceof SqliteDatabase   => new SqliteGrammar(),
            default => throw new \LogicException(
                sprintf('No schema grammar available for database class "%s".', $database::class)
            ),
        };
    }
}