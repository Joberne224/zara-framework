<?php

declare(strict_types=1);

namespace Zara\Database\Exceptions;

/**
 * Base exception for all database-related errors.
 */
class DatabaseException extends \RuntimeException
{
    /**
     * The SQLSTATE code from the underlying PDO error (e.g. "23000" for a
     * constraint violation, "42S02" for an unknown table), if this exception
     * wraps one.
     *
     * This is exposed as a separate accessor rather than through the standard
     * getCode() because PDOException::getCode() returns a SQLSTATE *string*,
     * not the int that \Exception::getCode()/getCode() is typed for — so it
     * cannot simply be forwarded as this exception's own code.
     */
    public function getSqlState(): ?string
    {
        $previous = $this->getPrevious();
        return $previous instanceof \PDOException ? (string) $previous->getCode() : null;
    }

    /**
     * The MySQL driver-specific error code (e.g. 1062 for a duplicate-key
     * violation), if this exception wraps a PDOException. This is more precise
     * than getSqlState() but not portable across database drivers — prefer
     * getSqlState() for logic that should also make sense against another
     * DatabaseInterface implementation.
     */
    public function getDriverCode(): ?int
    {
        $previous = $this->getPrevious();
        if (!$previous instanceof \PDOException) {
            return null;
        }
        $code = $previous->errorInfo[1] ?? null;
        return $code !== null ? (int) $code : null;
    }
}