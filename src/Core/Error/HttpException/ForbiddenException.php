<?php
declare(strict_types=1);

namespace Zara\Core\Error\HttpException;

final class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'Access forbidden.', ?\Throwable $previous = null)
    {
        parent::__construct(403, $message, $previous);
    }
}