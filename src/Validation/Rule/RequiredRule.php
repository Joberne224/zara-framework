<?php

declare(strict_types=1);

namespace Zara\Validation\Rule;

use Zara\Validation\Interfaces\RuleInterface;

final class RequiredRule implements RuleInterface
{
    public function passes(mixed $value, array $data): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    public function message(string $field): string
    {
        return "The {$field} field is required.";
    }
}