<?php

declare(strict_types=1);

namespace Zara\Database\Internal\Postgres;

use Psr\Log\LoggerInterface;
use Zara\Database\Exceptions\InvalidIdentifierException;
use Zara\Database\Internal\IdentifierQuoterInterface;

/**
 * Quotes identifiers using PostgreSQL's standard double-quote syntax.
 *
 * Identical logic to SqliteDatabase's IdentifierQuoter (both engines use
 * ANSI double quotes) — not shared here for the same reason StatementCache/
 * TransactionManager originally weren't: flag it and extract only if you
 * want a third "double-quote identifier" collaborator shared across
 * Sqlite and Postgres.
 */
final class IdentifierQuoter implements IdentifierQuoterInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function quote(string $identifier): string
    {
        // The /D modifier makes "$" match only at the very end of the string: without it,
        // PCRE lets "$" match before a trailing newline, so "id\n" would slip through.
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $identifier)) {
            // Neutralise control characters and cap the length before the (attacker-
            // controllable) identifier reaches logs or exception messages.
            $safe = substr(addcslashes($identifier, "\0..\37\177"), 0, 64);
            $this->logger->error('Invalid identifier: ' . $safe);

            throw new InvalidIdentifierException(sprintf('Invalid identifier: "%s".', $safe));
        }

        return '"' . $identifier . '"';
    }
}