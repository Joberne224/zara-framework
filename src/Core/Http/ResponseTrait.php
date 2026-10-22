<?php
declare(strict_types=1);

namespace Zara\Core\Http;

use DateTimeInterface;
use Psr\Http\Message\StreamInterface;
use Zara\Core\Http\Interfaces\ResponseInterface;

/**
 * Provides immutable delegation of ResponseInterface methods to an internal
 * Response instance. The using class must implement response() to expose
 * its composed Response, and withResponseInstance() to describe how to
 * rebuild itself around a new Response — since JsonResponse (for example)
 * cannot simply return the raw Response, it must stay a JsonResponse.
 */
trait ResponseTrait
{
    abstract protected function response(): ResponseInterface;
    abstract protected function withResponseInstance(ResponseInterface $response): static;

    public function getBody(): StreamInterface
    {
        return $this->response()->getBody();
    }

    public function getStatusCode(): int
    {
        return $this->response()->getStatusCode();
    }

    public function getProtocolVersion(): string
    {
        return $this->response()->getProtocolVersion();
    }

    public function getHeader(string $name): ?string
    {
        return $this->response()->getHeader($name);
    }

    public function getHeaders(): array
    {
        return $this->response()->getHeaders();
    }

    public function getCookies(): array
    {
        return $this->response()->getCookies();
    }

    public function withBody(string|StreamInterface $content): static
    {
        return $this->withResponseInstance($this->response()->withBody($content));
    }

    public function withStatusCode(int $statusCode): static
    {
        return $this->withResponseInstance($this->response()->withStatusCode($statusCode));
    }

    public function withProtocolVersion(string $version): static
    {
        return $this->withResponseInstance($this->response()->withProtocolVersion($version));
    }

    public function withHeader(string $name, string $value): static
    {
        return $this->withResponseInstance($this->response()->withHeader($name, $value));
    }

    public function withoutHeader(string $name): static
    {
        return $this->withResponseInstance($this->response()->withoutHeader($name));
    }

    public function withCookie(
        string $name,
        string $value,
        int|DateTimeInterface $expire = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): static {
        return $this->withResponseInstance(
            $this->response()->withCookie($name, $value, $expire, $path, $domain, $secure, $httpOnly, $sameSite)
        );
    }

    public function withoutCookie(
        string $name,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): static {
        return $this->withResponseInstance(
            $this->response()->withoutCookie($name, $path, $domain, $secure, $httpOnly, $sameSite)
        );
    }

    public function send(): void
    {
        $this->response()->send();
    }
}