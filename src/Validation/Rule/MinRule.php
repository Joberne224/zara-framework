<?php

declare(strict_types=1);

namespace Zara\Validation\Rule;

use Zara\Validation\Interfaces\RuleInterface;

final class MinRule implements RuleInterface
{
    public function __construct(
        private readonly int|float $min,
    ) {
    }

    public function passes(mixed $value, array $data): bool
    {
        if ($value === null || $value === '') {
            return true; // Absence is RequiredRule's concern.
        }

        if (is_string($value)) {
            return mb_strlen($value) >= $this->min;
        }

        if (is_numeric($value)) {
            return (float) $value >= $this->min;
        }

        if (is_array($value)) {
            return count($value) >= $this->min;
        }

        return false;
    }

    public function message(string $field): string
    {
        return "The {$field} field must be at least {$this->min}.";
    }
}