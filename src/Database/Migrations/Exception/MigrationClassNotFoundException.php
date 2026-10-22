<?php

declare(strict_types=1);

namespace Zara\Database\Migrations\Exception;

/**
 * Thrown when, after requiring a migration file, the expected class
 * (derived from the filename) does not exist or does not extend Migration.
 * This indicates a malformed migration file or one that was renamed
 * manually after generation.
 */
final class MigrationClassNotFoundException extends MigrationException
{
}
