<?php
declare(strict_types=1);

namespace Zara\Core\Http\Interfaces;

use Psr\Http\Message\StreamInterface;

interface ServerRequestFactoryInterface
{
    public function createFromGlobals(): RequestInterface;

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $files
     * @param array<string, mixed>|HeadersInterface $headers
     */
    public function createFromArrays(
        array $server,
        array $query,
        array $post,
        array $cookies,
        array $files,
        array|HeadersInterface $headers = [],
        string|StreamInterface $body = '',
    ): RequestInterface;
}