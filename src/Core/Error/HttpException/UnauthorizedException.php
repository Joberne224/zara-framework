<?php
declare(strict_types=1);

namespace Zara\Core\Error\HttpException;

final class UnauthorizedException extends HttpException
{
    public function __construct(
        string $message = 'Unauthorized access.',
        ?\Throwable $previous = null
    ) {
        parent::__construct(401, $message, $previous);
    }
}