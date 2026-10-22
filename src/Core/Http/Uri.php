<?php
declare(strict_types=1);

namespace Zara\Core\Http;

use InvalidArgumentException;
use Zara\Core\Http\Interfaces\UriInterface;

final class Uri implements UriInterface
{
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    private string $scheme;
    private string $userInfo;
    private string $host;
    private ?int $port;
    private string $path;
    private string $query;
    private string $fragment;

    public function __construct(string $uri = '')
    {
        $parts = parse_url($uri);
        if ($parts === false) {
            throw new InvalidArgumentException("The URI '$uri' is not valid.");
        }

        $this->scheme   = strtolower($parts['scheme'] ?? '');
        $this->userInfo = $this->parseUserInfo($parts);
        $this->host     = strtolower($parts['host'] ?? '');
        $this->port     = $this->validatePort($parts['port'] ?? null);
        $this->path     = $parts['path'] ?? '';
        $this->query    = $parts['query'] ?? '';
        $this->fragment = $parts['fragment'] ?? '';
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        if ($this->port !== null && (self::DEFAULT_PORTS[$this->scheme] ?? null) === $this->port) {
            return null;
        }

        return $this->port;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }

        $authority = $this->userInfo !== '' ? $this->userInfo . '@' . $this->host : $this->host;
        $port = $this->getPort();

        return $port === null ? $authority : $authority . ':' . $port;
    }

    public function getBasePath(): string
    {
        return dirname($this->path);
    }

    public function withScheme(string $scheme): self
    {
        $clone = clone $this;
        $clone->scheme = strtolower($scheme);
        return $clone;
    }

    public function withUserInfo(string $user, ?string $password = null): self
    {
        $clone = clone $this;
        $clone->userInfo = $user === '' ? '' : ($password === null ? $user : $user . ':' . $password);
        return $clone;
    }

    public function withHost(string $host): self
    {
        $clone = clone $this;
        $clone->host = strtolower($host);
        return $clone;
    }

    public function withPort(?int $port): self
    {
        $clone = clone $this;
        $clone->port = $this->validatePort($port);
        return $clone;
    }

    public function withPath(string $path): self
    {
        $clone = clone $this;
        $clone->path = $path;
        return $clone;
    }

    public function withQuery(string $query): self
    {
        $clone = clone $this;
        $clone->query = ltrim($query, '?');
        return $clone;
    }

    public function withFragment(string $fragment): self
    {
        $clone = clone $this;
        $clone->fragment = ltrim($fragment, '#');
        return $clone;
    }

     public function __toString(): string
    {
        $uri = '';
        
        if ($this->scheme !== '') {
            $uri .= $this->scheme . '://';
        }

        if ($this->host !== '') {
            if ($this->userInfo !== '') {
                $uri .= $this->userInfo . '@';
            }
            $uri .= $this->host;
            
            if ($this->getPort() !== null) {
                $uri .= ':' . $this->getPort();
            }
        }

        $uri .= $this->path;

        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }

        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }

        return $uri;
    }

    private function parseUserInfo(array $parts): string
    {
        $userInfo = $parts['user'] ?? '';
        if (isset($parts['pass'])) {
            $userInfo .= ':' . $parts['pass'];
        }
        return $userInfo;
    }

    private function validatePort(?int $port): ?int
    {
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new InvalidArgumentException("The port '$port' is invalid. Must be between 1 and 65535.");
        }
        return $port;
    }
}