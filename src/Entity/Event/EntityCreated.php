<?php
declare(strict_types=1);

namespace Zara\Entity\Event;

final class EntityCreated
{
    public function __construct(public readonly object $entity)
    {
    }
}





