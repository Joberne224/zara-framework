<?php

declare(strict_types=1);

namespace Zara\View\Exceptions;

use RuntimeException;

final class AttributeNotFoundException extends RuntimeException
{
    public static function forAttribute(string $target, string $attribute): self
    {
        return new self("Attribute '{$attribute}' not found on target '{$target}'.");
    }
}