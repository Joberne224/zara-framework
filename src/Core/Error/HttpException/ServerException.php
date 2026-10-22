<?php
declare(strict_types=1);

namespace Zara\Core\Error\HttpException;

final class ServerException extends HttpException
{
    public function __construct(string $message = 'Internal server error.', ?\Throwable $previous = null)
    {
        parent::__construct(500, $message, $previous);
    }
}