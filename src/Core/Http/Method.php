<?php
declare(strict_types=1);

namespace Zara\Core\Http;

use InvalidArgumentException;
use Zara\Core\Http\Interfaces\MethodInterface;

final class Method implements MethodInterface
{
    private const ALLOWED_METHODS = [
        'GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS', 'TRACE', 'CONNECT'
    ];
    
    private string $name;

    public function __construct(string $name)
    {
        $name = strtoupper($name);
        if (!in_array($name, self::ALLOWED_METHODS, true)) {
            throw new InvalidArgumentException("The HTTP method '$name' is not valid.");
        }
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isGet(): bool
    {
        return $this->name === 'GET';
    }

    public function isPost(): bool
    {
        return $this->name === 'POST';
    }

    public function isPut(): bool
    {
        return $this->name === 'PUT';
    }

    public function isDelete(): bool
    {
        return $this->name === 'DELETE';
    }

    public function isSafe(): bool
    {
        return in_array($this->name, ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true);
    }

    public function isIdempotent(): bool
    {
        return in_array($this->name, ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS', 'TRACE'], true);
    }

    public function __toString(): string
    {
        return $this->name;
    }
}