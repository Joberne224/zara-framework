<?php

declare(strict_types=1);

namespace Zara\View\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when template engine evaluation fails within the View wrapper.
 */
class ViewRenderException extends RuntimeException
{
    public function __construct(
        string $message,
        int $code = 500,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}