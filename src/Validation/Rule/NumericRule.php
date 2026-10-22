<?php

declare(strict_types=1);

namespace Zara\Validation\Rule;

use Zara\Validation\Interfaces\RuleInterface;

final class NumericRule implements RuleInterface
{
    public function passes(mixed $value, array $data): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return is_numeric($value);
    }

    public function message(string $field): string
    {
        return "The {$field} field must be numeric.";
    }
}