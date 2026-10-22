<?php

declare(strict_types=1);

namespace Zara\Validation\Rule;

use Zara\Validation\Interfaces\RuleInterface;

final class InRule implements RuleInterface
{
    /** @param scalar[] $allowed */
    public function __construct(
        private readonly array $allowed,
    ) {
    }

    public function passes(mixed $value, array $data): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return in_array($value, $this->allowed, true);
    }

    public function message(string $field): string
    {
        $list = implode(', ', array_map('strval', $this->allowed));

        return "The {$field} field must be one of: {$list}.";
    }
}