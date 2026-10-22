<?php

declare(strict_types=1);

namespace Zara\Database\Migrations;

use Zara\Database\DatabaseInterface;
use Zara\Database\Schema\Schema;

/**
 * Contrat que toute migration doit remplir.
 *
 * $db remains available for cases outside the schema builder's scope
 * (one-off raw SQL, seed DML). $schema is the standard entry point for DDL
 * operations (create/table/drop), portable across all three drivers without
 * requiring the migration to know which dialect is active.
 */
abstract class Migration
{
    abstract public function up(DatabaseInterface $db, Schema $schema): void;

    abstract public function down(DatabaseInterface $db, Schema $schema): void;
}
