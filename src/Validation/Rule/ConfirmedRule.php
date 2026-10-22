<?php

declare(strict_types=1);

namespace Zara\Validation\Rule;

use Zara\Validation\Interfaces\RuleInterface;

final class ConfirmedRule implements RuleInterface
{
    public function __construct(
        private readonly string $confirmationField,
    ) {
    }

    public function passes(mixed $value, array $data): bool
    {
        return ($data[$this->confirmationField] ?? null) === $value;
    }

    public function message(string $field): string
    {
        return "The {$field} field confirmation does not match.";
    }
}