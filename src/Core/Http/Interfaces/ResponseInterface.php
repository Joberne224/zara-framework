<?php
declare(strict_types=1);

namespace Zara\Core\Http\Interfaces;

use DateTimeInterface;
use Psr\Http\Message\StreamInterface;

/**
 * @phpstan-type CookieSpec array{name: string, value: string, expire: int, path: string, domain: string, secure: bool, httpOnly: bool, sameSite: string}
 */
interface ResponseInterface
{
    public function getBody(): StreamInterface;
    public function getStatusCode(): int;
    public function getProtocolVersion(): string;
    public function getHeader(string $name): ?string;
    public function getHeaders(): array;

    /**
     * Cookies queued for emission by send(). The same name may appear several
     * times with different paths or domains.
     *
     * @return list<CookieSpec>
     */
    public function getCookies(): array;

    public function withBody(string|StreamInterface $body): static;
    public function withStatusCode(int $statusCode): static;
    public function withProtocolVersion(string $version): static;
    public function withHeader(string $name, string $value): static;
    public function withoutHeader(string $name): static;

    /**
     * Queues a cookie. $expire is 0 (session cookie) or an ABSOLUTE expiry,
     * never a duration.
     */
    public function withCookie(
        string $name,
        string $value,
        int|DateTimeInterface $expire = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): static;

    /**
     * Queues the deletion of a cookie: pass the same $path and $domain as the
     * original withCookie().
     */
    public function withoutCookie(
        string $name,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): static;

    public function send(): void;
}