<?php

declare(strict_types=1);

namespace Zara\Database\Schema;

/**
 * Translates a Blueprint (abstract intentions) into SQL statements for one
 * driver. Each method returns an ordered list of statements to run one after
 * another: a single statement for a simple CREATE TABLE, several for a
 * CREATE TABLE followed by separate CREATE INDEX (Postgres, Sqlite), or for
 * an ALTER TABLE that adds several columns/indexes at once.
 */
interface SchemaGrammarInterface
{
    /** @return list<string> */
    public function compileCreate(Blueprint $blueprint, bool $ifNotExists = false): array;

    /** @return list<string> */
    public function compileAlter(Blueprint $blueprint): array;

    public function compileDrop(string $table): string;

    public function compileDropIfExists(string $table): string;

    /**
     * Whether DDL statements can run inside a transaction and be rolled back.
     * False on MySQL, which commits implicitly on every CREATE/ALTER/DROP.
     */
    public function supportsTransactionalDdl(): bool;
}