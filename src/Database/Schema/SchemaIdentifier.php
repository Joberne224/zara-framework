<?php
declare(strict_types=1);

namespace Zara\Database\Schema;

use Zara\Database\Exceptions\InvalidIdentifierException;

/** Shared allowlist for identifiers emitted by schema grammars. */
final class SchemaIdentifier
{
    public static function validate(string $identifier): string
    {
        if (preg_match('/^[a-zA-Z0-9_]+$/D', $identifier) !== 1) {
            throw new InvalidIdentifierException('Invalid schema identifier. Only letters, digits and underscores are allowed.');
        }

        return $identifier;
    }

    private function __construct() {}
}
