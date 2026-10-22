<?php

declare(strict_types=1);

namespace Zara\Validation\Rule;

use Zara\Validation\Interfaces\RuleInterface;

final class MaxRule implements RuleInterface
{
    public function __construct(
        private readonly int|float $max,
    ) {
    }

    public function passes(mixed $value, array $data): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        if (is_string($value)) {
            return mb_strlen($value) <= $this->max;
        }

        if (is_numeric($value)) {
            return (float) $value <= $this->max;
        }

        if (is_array($value)) {
            return count($value) <= $this->max;
        }

        return false;
    }

    public function message(string $field): string
    {
        return "The {$field} field must not exceed {$this->max}.";
    }
}