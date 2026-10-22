<?php
declare(strict_types=1);

namespace Zara\Entity\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Length
{
    public function __construct(
        public readonly int $min = 0,
        public readonly int $max = 255,
        public readonly string $message = 'Length is invalid'
    ) {}
}