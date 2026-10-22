<?php

namespace Zara\Core\Http;

use Zara\Core\Http\Interfaces\ResponseFactoryInterface;
use Zara\Core\Http\Interfaces\ResponseInterface;

final class ResponseFactory implements ResponseFactoryInterface
{
    public function json(mixed $data, int $statusCode = HttpStatusCode::OK): ResponseInterface
    {
        return new JsonResponse($data, $statusCode);
    }

    public function html(string $content, int $statusCode = HttpStatusCode::OK): ResponseInterface
    {
        return (new Response($content, $statusCode))
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}