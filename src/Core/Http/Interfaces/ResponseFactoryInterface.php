<?php

namespace Zara\Core\Http\Interfaces;

use Zara\Core\Http\HttpStatusCode;

interface ResponseFactoryInterface
{
    public function json(mixed $data, int $statusCode = HttpStatusCode::OK): ResponseInterface;

    public function html(string $content, int $statusCode = HttpStatusCode::OK): ResponseInterface;
}