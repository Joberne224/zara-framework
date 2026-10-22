<?php
declare(strict_types=1);

namespace Zara\Entity\Event;

final class EntityUpdated
{
    /** @param string[] $changedColumns */
    public function __construct(
        public readonly object $entity,
        public readonly array $changedColumns,
    ) {
    }
}