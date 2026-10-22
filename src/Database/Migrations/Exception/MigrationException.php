<?php

declare(strict_types=1);

namespace Zara\Database\Migrations\Exception;

use RuntimeException;

/**
 * Common base class for all migration system exceptions.
 * It follows the same convention as Zara\Database\Exceptions\DatabaseException:
 * concrete exceptions inherit from this abstract root, allowing callers to
 * catch either all migration errors (MigrationException) or a specific case.
 */
abstract class MigrationException extends RuntimeException
{
}
