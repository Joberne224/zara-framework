<?php

declare(strict_types=1);

namespace Zara\Validation\Exceptions;

use Zara\Validation\ValidationResult;

final class ValidationException extends \RuntimeException
{
    public function __construct(
        public readonly ValidationResult $result,
    ) {
        parent::__construct('Validation failed.');
    }
}