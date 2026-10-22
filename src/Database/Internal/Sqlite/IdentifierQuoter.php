<?php

declare(strict_types=1);

namespace Zara\Database\Internal\Sqlite;

use Zara\Database\Exceptions\InvalidIdentifierException;
use Zara\Database\Internal\IdentifierQuoterInterface;

/**
 * Quotes identifiers using SQLite's standard double-quote syntax.
 *
 * Same restriction as the MySQL quoter: only [a-zA-Z0-9_]+ is accepted,
 * qualified identifiers (table.col) are rejected by design — callers build
 * qualified names by quoting each part separately.
 */
final class IdentifierQuoter implements IdentifierQuoterInterface
{
    public function quote(string $identifier): string
    {
        // The /D modifier makes "$" match only at the very end of the string: without it,
        // PCRE lets "$" match before a trailing newline, so "id\n" would slip through.
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $identifier)) {
            // Neutralise control characters and cap the length before the (attacker-
            // controllable) identifier reaches an exception message that may get logged.
            $safe = substr(addcslashes($identifier, "\0..\37\177"), 0, 64);

            throw new InvalidIdentifierException(sprintf('Invalid identifier: "%s".', $safe));
        }

        return '"' . $identifier . '"';
    }
}