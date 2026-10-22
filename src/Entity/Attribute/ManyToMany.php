<?php
declare(strict_types=1);

namespace Zara\Entity\Attribute;

/**
 * Declared on BOTH sides of the relation, with pivotOwnerColumn/
 * pivotTargetColumn swapped between them — e.g. on Student:
 * pivotOwnerColumn: 'student_id', pivotTargetColumn: 'course_id'; on
 * Course: the two swapped. Neither side owns a foreign key column itself;
 * the pivot table is the only place the association is actually stored.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class ManyToMany
{
    public function __construct(
        public readonly string $target,
        public readonly string $pivotTable,
        public readonly string $pivotOwnerColumn,
        public readonly string $pivotTargetColumn,
    ) {
    }
}