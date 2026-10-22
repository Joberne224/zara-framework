<?php
declare(strict_types=1);

namespace Zara\Core\Http\Middleware;

use Zara\Auth\Interfaces\AuthenticationInterface;
use Zara\Core\Http\RedirectResponse;
use Zara\Core\Http\Response;
use Zara\Core\Http\Interfaces\RequestInterface;
use Zara\Core\Http\Interfaces\ResponseInterface;
use Zara\Core\Http\Interfaces\MiddlewareInterface;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthenticationInterface $auth,
        private readonly string $loginPath = '/login',
        private readonly array $requiredRoles = [],
    ) {
    }

    public function handle(RequestInterface $request, callable $next): ResponseInterface
    {   
        $identity = $this->auth->user();
        if ($identity === null) {
            return new RedirectResponse($this->loginPath);
        }

        if ($this->requiredRoles !== [] && array_intersect($this->requiredRoles, $identity->getRoles()) === []) {
            return new Response('Forbidden', 403);
        }

        return $next($request);
    }
}
