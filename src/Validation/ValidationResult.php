<?php

declare(strict_types=1);

namespace Zara\Validation;

final class ValidationResult
{
    /** @var array<string, string[]> */
    private array $errors = [];

    public function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /** @return array<string, string[]> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return string[] */
    public function get(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    public function first(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }
}