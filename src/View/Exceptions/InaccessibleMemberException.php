<?php

declare(strict_types=1);

namespace Zara\View\Exceptions;

use RuntimeException;

final class InaccessibleMemberException extends RuntimeException
{
    public static function forMember(string $target, string $member): self
    {
        return new self("Member '{$member}' is inaccessible on target '{$target}'.");
    }
}