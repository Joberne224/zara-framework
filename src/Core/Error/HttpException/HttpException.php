<?php
declare(strict_types=1);

namespace Zara\Core\Error\HttpException;

/**
 * Base exception for HTTP errors.
 *
 * ErrorHandler uses the status code from this exception in the response and
 * maps 4xx errors to warning logs and 5xx errors to error logs.
 */
class HttpException extends \RuntimeException
{
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        if ($statusCode < 400 || $statusCode > 599) {
            throw new \InvalidArgumentException('HTTP exception status code must be between 400 and 599.');
        }

        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getStatusMessage(): string
    {
        return match ($this->statusCode) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            408 => 'Request Timeout',
            409 => 'Conflict',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            default => 'HTTP Error',
        };
    }
}
