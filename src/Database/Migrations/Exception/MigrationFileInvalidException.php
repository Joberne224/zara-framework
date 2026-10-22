<?php

declare(strict_types=1);

namespace Zara\Database\Migrations\Exception;

/**
 * Thrown when a filename in the migrations directory does not match the
 * expected Y_m_d_His_snake_case_name format, typically because it was added
 * or renamed manually outside the make:migration workflow.
 */
final class MigrationFileInvalidException extends MigrationException
{
}
