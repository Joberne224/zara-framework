<?php

declare(strict_types=1);

namespace Zara\Validation\Interfaces;

use Zara\Validation\Exceptions\ValidationException;
use Zara\Validation\ValidationResult;

interface ValidatorInterface
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string|array<int, RuleInterface|string>> $rules
     */
    public function validate(array $data, array $rules): ValidationResult;

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|array<int, RuleInterface|string>> $rules
     *
     * @throws ValidationException
     */
    public function validateOrFail(array $data, array $rules): ValidationResult;
}
