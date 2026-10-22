<?php
declare(strict_types=1);

namespace Zara\Entity\Attribute;

use Zara\Entity\Casting\ValueCasterInterface;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Column
{
    public function __construct(
        public readonly string $name,
        /** @var class-string<ValueCasterInterface>|null */
        public readonly ?string $caster = null,
    ) {
    }
}