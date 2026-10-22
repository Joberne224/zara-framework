<?php

declare(strict_types=1);

namespace Zara\Database\Internal;

interface StatementExecutorInterface
{
    /**
     * @throws \PDOException Left uncaught on purpose: AbstractSqlDatabase's facade
     *   methods are what translate this into the right QueryException.
     */
    public function execute(string $query, array $params): \PDOStatement;
}