<?php

declare(strict_types=1);

namespace Zara\Database\Schema\Exception;

/**
 * Thrown when a Grammar receives a Blueprint it cannot faithfully support
 * for its driver. For example, SQLite does not support adding a foreign key
 * through ALTER TABLE, only during table creation. This is distinct from a
 * regular SQL error: it is a known and documented dialect limitation.
 */
final class UnsupportedSchemaOperationException extends SchemaException
{
}
