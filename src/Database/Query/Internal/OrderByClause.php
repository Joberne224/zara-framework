<?php

declare(strict_types=1);

namespace Zara\Database\Query\Internal;

final class OrderByClause
{
    public function __construct(
        public readonly string $column,
        public readonly SortDirection $direction,
    ) {
    }
}