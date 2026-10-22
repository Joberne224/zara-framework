<?php
declare(strict_types=1);

namespace Zara\Entity\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Table
{
    public function __construct(
        public readonly string $name,
    ) {
    }
}