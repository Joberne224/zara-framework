<?php
//File: zara-framework/src/Database/Schema/IndexDefinition.php
declare(strict_types=1);

namespace Zara\Database\Schema;

final class IndexDefinition
{
    /** @param list<string> $columns */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly bool $unique = false,
    ) {
    }
}
