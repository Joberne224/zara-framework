<?php
declare(strict_types=1);

namespace Zara\Core\Http\Interfaces;

use Zara\Core\Files\Interfaces\FileCollectionInterface;
use Psr\Http\Message\StreamInterface;
use Zara\Core\Http\ParameterBag;

interface RequestInterface
{
    public function withUri(UriInterface $uri): static;

    public function withHeaders(HeadersInterface $headers): static;

    public function withHeader(string $name, string|array $value): static;

    public function withBody(string|StreamInterface $body): static;

    public function withAttribute(string $name, mixed $value): static;

    public function getAttribute(string $name, mixed $default = null): mixed;

    public function getAttributes(): array;

    public function getQueryParams(): ParameterBag;

    public function getPostParams(): ParameterBag;

    public function getServerParams(): ParameterBag;

    public function getCookie(): ParameterBag;

    public function getMethod(): MethodInterface;

    public function getUri(): UriInterface;

    public function getHeaders(): HeadersInterface;

    public function getBody(): StreamInterface;

    public function getFiles(): FileCollectionInterface;
}