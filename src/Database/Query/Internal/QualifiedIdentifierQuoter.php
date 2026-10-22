<?php

declare(strict_types=1);

namespace Zara\Database\Query\Internal;

use Zara\Database\DatabaseInterface;

/**
 * Wraps DatabaseInterface::quoteIdentifier() to handle the two extra shapes
 * the query builder needs that a bare identifier quoter isn't expected to:
 * dotted "table.column" references (each segment quoted independently) and
 * the unquoted "*" wildcard.
 *
 * This is also the ONLY place in the query builder that knows how to quote
 * anything — and even it delegates the actual quoting character/rules to
 * whatever DatabaseInterface implementation it's given. That's what makes
 * QueryBuilder database-agnostic: swap MysqlDatabase for a future
 * PostgresDatabase and every identifier in every compiled query is quoted
 * correctly, with zero changes here.
 *
 * Anything more exotic than a plain or dotted identifier — expressions,
 * function calls, aliases — is deliberately out of scope. Teaching this class
 * to parse SQL would be exactly the kind of magic Zara avoids; use
 * QueryBuilder's whereRaw()/havingRaw() escape hatches for that instead.
 */
final class QualifiedIdentifierQuoter
{
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function quote(string $identifier): string
    {
        if ($identifier === '*') {
            return '*';
        }

        if (!str_contains($identifier, '.')) {
            return $this->db->quoteIdentifier($identifier);
        }

        return implode('.', array_map(
            fn (string $segment): string => $segment === '*' ? '*' : $this->db->quoteIdentifier($segment),
            explode('.', $identifier)
        ));
    }
}