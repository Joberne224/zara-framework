<?php

declare(strict_types=1);

namespace Zara\Core\Files;

final class FileValidationResult
{
    /** @param string[] $errors */
    public function __construct(
        private readonly bool $valid,
        private readonly array $errors = [],
    ) {}

    public function isValid(): bool
    {
        return $this->valid;
    }

    /** @return string[] */
    public function getErrors(): array
    {
        return $this->errors;
    }
}