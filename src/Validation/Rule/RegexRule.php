<?php

declare(strict_types=1);

namespace Zara\Validation\Rule;

use Zara\Validation\Interfaces\RuleInterface;

final class RegexRule implements RuleInterface
{
    public function __construct(
        private readonly string $pattern,
    ) {
    }

    public function passes(mixed $value, array $data): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return is_string($value) && preg_match($this->pattern, $value) === 1;
    }

    public function message(string $field): string
    {
        return "The {$field} field format is invalid.";
    }
}