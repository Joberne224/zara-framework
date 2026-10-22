<?php
declare(strict_types=1);    

namespace Zara\Entity\Event;

final class EntityDeleted
{
    public function __construct(
        public readonly string $entityClass,
        public readonly int|string $id,
    ) {
    }
}