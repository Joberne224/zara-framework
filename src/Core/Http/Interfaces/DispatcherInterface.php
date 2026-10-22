<?php
declare(strict_types=1);

namespace Zara\Core\Http\Interfaces;

use \Closure;

interface DispatcherInterface
{
    public function dispatch(array $middlewares, Closure $handler, RequestInterface $request): ResponseInterface;
}