<?php
declare(strict_types=1);

namespace Zara\Container\Registry;

final class ResolutionModeGuard
{
    private bool $strict = false;

    public function enableStrict(): void
    {
        $this->strict = true;
    }

    public function isStrict(): bool
    {
        return $this->strict;
    }
}