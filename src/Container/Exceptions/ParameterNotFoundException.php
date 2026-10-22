<?php

declare(strict_types=1);

namespace Zara\Container\Exceptions;

use Exception;  

class ParameterNotFoundException extends Exception
{
    public function __construct(string $message = "Parameter not found.", int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}