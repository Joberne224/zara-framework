<?php

declare(strict_types=1);

namespace Zara\Container\Interfaces;

use Zara\Core\Http\Interfaces\RequestInterface;

/**
 * Narrow contract allowing Kernel to republish the current Request as a
 * synthetic service after route matching updates it (e.g. via
 * withAttribute('_route', ...)). Request is registered as a synthetic
 * service in index.php before routing runs; PSR-7-style immutability means
 * every with*() call produces a new instance, so the container's copy must
 * be explicitly refreshed or it goes stale the moment routing attaches
 * anything to the request.
 */
interface RequestBinderInterface
{
    public function rebindRequest(RequestInterface $request): void;
}