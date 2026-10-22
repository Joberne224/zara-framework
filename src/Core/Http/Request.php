<?php
declare(strict_types=1);

namespace Zara\Core\Http;

use Zara\Core\Http\Interfaces\RequestInterface;
use Zara\Core\Files\Interfaces\FileCollectionInterface;
use Zara\Core\Http\Interfaces\UriInterface;
use Zara\Core\Http\Interfaces\HeadersInterface;
use Zara\Core\Http\Interfaces\MethodInterface;
use Psr\Http\Message\StreamInterface;

class Request implements RequestInterface
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    private StreamInterface $body;

    public function __construct(
        private ParameterBag $queryParams,
        private ParameterBag $postParams,
        private ParameterBag $serverParams,
        private FileCollectionInterface $files,
        private ParameterBag $cookie,
        private MethodInterface $method,
        private UriInterface $uri,
        private HeadersInterface $headers,
        string|StreamInterface $body = '',
    ) {
        $this->body = $body instanceof StreamInterface ? $body : Stream::fromString($body);
    }

    public function withUri(UriInterface $uri): static
    {
        $request = clone $this;
        $request->uri = $uri;
        return $request;
    }

    public function withHeaders(HeadersInterface $headers): static
    {
        $request = clone $this;
        $request->headers = $headers;
        return $request;
    }

    public function withHeader(string $name, string|array $value): static
    {
        $request = clone $this;
        $request->headers = $this->headers->with($name, $value);
        return $request;
    }

    public function withBody(string|StreamInterface $body): static
    {
        $request = clone $this;
        $request->body = $body instanceof StreamInterface ? $body : Stream::fromString($body);
        return $request;
    }

    public function withAttribute(string $name, mixed $value): static
    {
        $request = clone $this;
        $request->attributes[$name] = $value;
        return $request;
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getQueryParams(): ParameterBag
    {
        return $this->queryParams;
    }

    public function getPostParams(): ParameterBag
    {
        return $this->postParams;
    }

    public function getServerParams(): ParameterBag
    {
        return $this->serverParams;
    }

    public function getCookie(): ParameterBag
    {
        return $this->cookie;
    }

    public function getMethod(): MethodInterface
    {
        return $this->method;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function getHeaders(): HeadersInterface
    {
        return $this->headers;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function getFiles(): FileCollectionInterface
    {
        return $this->files;
    }
}