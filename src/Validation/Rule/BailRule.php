<?php

declare(strict_types=1);

namespace Zara\Validation\Rule;

use Zara\Validation\Interfaces\RuleInterface;

final class BailRule implements RuleInterface
{
    public function passes(mixed $value, array $data): bool
    {
        return true;
    }

    public function message(string $field): string
    {
        return '';
    }
}
