<?php
declare(strict_types=1);

namespace Zara\Core\Error\HttpException;

final class MethodNotAllowedException extends HttpException
{
    /** @param string[] $allowedMethods */
    public function __construct(
        private readonly array $allowedMethods = [],
        string $message = 'Method not allowed.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct(405, $message, $previous);
    }

    /** @return string[] */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }
}