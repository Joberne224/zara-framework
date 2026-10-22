<?php

namespace Zara\Core\Http\Interfaces;

interface MiddlewareInterface
{
    public function handle(RequestInterface $request, callable $next);
}