<?php

declare(strict_types=1);

namespace Zara\Database\Internal\Mysql;

use Psr\Log\LoggerInterface;
use Zara\Database\Exceptions\InvalidIdentifierException;
use Zara\Database\Internal\IdentifierQuoterInterface;

/**
 * Validates and quotes SQL identifiers (table/column names) to prevent SQL
 * injection through identifiers, which cannot be parameterized via placeholders.
 *
 * Deliberately rejects qualified identifiers (e.g. "users.id" or "t.col AS alias"):
 * accepting a dot or a space would widen the allowlist regex enough to also let
 * through some forms of SQL injection through this same parameter, and there is
 * no query-agnostic way to validate "the dotted/aliased parts are also safe"
 * without effectively parsing SQL. Callers needing joins/aliases should build
 * that fragment of the query themselves via query()/execute() rather than through
 * this method.
 */
final class IdentifierQuoter implements IdentifierQuoterInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @throws InvalidIdentifierException If the identifier is invalid
     */
    public function quote(string $identifier): string
    {
        // The /D modifier makes "$" match only at the very end of the string: without it,
        // PCRE lets "$" match before a trailing newline, so "id\n" would slip through.
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $identifier)) {
            // Neutralise control characters and cap the length before the (attacker-
            // controllable) identifier reaches logs or exception messages.
            $safe = substr(addcslashes($identifier, "\0..\37\177"), 0, 64);
            $this->logger->warning('Invalid SQL identifier: ' . $safe);
            throw new InvalidIdentifierException(
                sprintf('Invalid SQL identifier: %s. Only alphanumeric characters and underscores are allowed.', $safe)
            );
        }

        // For MySQL, backticks are used to quote identifiers
        return '`' . $identifier . '`';
    }
}