<?php
declare(strict_types=1);

namespace Zara\Entity\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class ManyToOne
{
    public function __construct(
        public readonly string $target,
        public readonly string $localKey,
    ) {
    }
}