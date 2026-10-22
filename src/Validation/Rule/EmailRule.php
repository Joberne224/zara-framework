<?php

declare(strict_types=1);

namespace Zara\Validation\Rule;

use Zara\Validation\Interfaces\RuleInterface;

final class EmailRule implements RuleInterface
{
    public function passes(mixed $value, array $data): bool
    {
        if (!is_string($value) || $value === '') {
            return true; // Absence/emptiness is RequiredRule's concern, not this one's.
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function message(string $field): string
    {
        return "The {$field} field must be a valid email address.";
    }
}