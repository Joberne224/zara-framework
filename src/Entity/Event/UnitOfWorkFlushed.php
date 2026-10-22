<?php
declare(strict_types=1);

namespace Zara\Entity\Event;

final class UnitOfWorkFlushed
{
    /**
     * @param object[] $savedEntities
     * @param array<int, array{0: class-string, 1: int|string}> $deletedKeys
     */
    public function __construct(
        public readonly array $savedEntities,
        public readonly array $deletedKeys,
    ) {
    }
}