<?php

declare(strict_types=1);

namespace Zara\View\Interfaces;

use Zara\Core\Http\HttpStatusCode;
use Zara\Core\Http\Interfaces\ResponseInterface;

interface ViewInterface 
{
    public function render(string $template, array $data = [], int $statusCode = HttpStatusCode::OK): ResponseInterface;
}