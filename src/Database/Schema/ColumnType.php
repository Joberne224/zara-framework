<?php
//File: zara-framework/src/Database/Schema/ColumnType.php
declare(strict_types=1);

namespace Zara\Database\Schema;

/**
 * Abstract types that a migration can declare. Each Grammar (MySQL,
 * Postgres, SQLite) maps these cases to its own SQL types. This is the main
 * benefit of the schema builder: a migration using these types runs unchanged
 * on all three drivers.
 */
enum ColumnType
{
    case Id;
    case String;
    case Text;
    case Integer;
    case BigInteger;
    case Boolean;
    case Decimal;
    case DateTime;
    case Date;
    case Json;
}
