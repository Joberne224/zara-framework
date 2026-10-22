<?php
declare(strict_types=1);

namespace Zara\Core\Http;

use Psr\Http\Message\StreamInterface;
use Zara\Core\Files\Interfaces\FileNormalizerInterface;
use Zara\Core\Http\Interfaces\HeadersInterface;
use Zara\Core\Http\Interfaces\RequestInterface;
use Zara\Core\Http\Interfaces\ServerRequestFactoryInterface;

final class ServerRequestFactory implements ServerRequestFactoryInterface
{
    public function __construct(
        private readonly FileNormalizerInterface $fileNormalizer,
    ) {
    }

    public function createFromGlobals(): RequestInterface
    {
        return $this->createFromArrays(
            server: $_SERVER,
            query: $_GET,
            post: $_POST,
            cookies: $_COOKIE,
            files: $_FILES,
            headers: Headers::createFromGlobals(),
            body: Stream::fromFile('php://input', 'rb'),
        );
    }

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
    ): RequestInterface {
        $headersInstance = match (true) {
            is_array($headers) && $headers === [] => Headers::createFromServer($server),
            is_array($headers) => new Headers($headers),
            $headers instanceof HeadersInterface => $headers,
        };

        return new Request(
            queryParams: new ParameterBag($query),
            postParams: new ParameterBag($post),
            serverParams: new ParameterBag($server),
            files: $this->fileNormalizer->normalize($files),
            cookie: new ParameterBag($cookies),
            method: new Method((string) ($server['REQUEST_METHOD'] ?? 'GET')),
            uri: new Uri((string) ($server['REQUEST_URI'] ?? '/')),
            headers: $headersInstance,
            body: $body,
        );
    }
}