<?php

declare(strict_types=1);

namespace Zara\Validation\Interfaces;

interface RuleInterface
{
    /**
     * @param array<string, mixed> $data Full input set, for cross-field rules (e.g. confirmation).
     */
    public function passes(mixed $value, array $data): bool;

    public function message(string $field): string;
}