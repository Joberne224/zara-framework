<?php

declare(strict_types=1);

namespace Zara\Database\Internal\Sqlite;

use PDOStatement;
use Zara\Database\Internal\Shared\StatementCache;
use Zara\Database\Internal\StatementExecutorInterface;

final class StatementExecutor implements StatementExecutorInterface
{
    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly StatementCache $statements,
    ) {
    }

    public function execute(string $query, array $params): PDOStatement
    {
        $this->connections->connect();
        $pdo = $this->connections->getPdo();
        $statement = $this->statements->prepare($pdo, $query);
        $statement->execute($params);

        return $statement;
    }
}