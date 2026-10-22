<?php
declare(strict_types=1);

namespace Zara\Core\Http\Middleware;

use Zara\Auth\Interfaces\AuthenticationInterface;
use Zara\Authorization\Authorization;
use Zara\Authorization\Interfaces\AuthorizationInterface;
use Zara\Permission\PermissionRegistry;
use Zara\Core\Http\RedirectResponse;
use Zara\Core\Http\Response;
use Zara\Core\Http\Interfaces\RequestInterface;
use Zara\Core\Http\Interfaces\ResponseInterface;
use Zara\Core\Http\Interfaces\MiddlewareInterface;

final class AuthMiddleware implements MiddlewareInterface
{
    private readonly AuthorizationInterface $authorization;

    /**
     * @param list<string> $requiredRoles Access is allowed when any listed role is present
     * @param list<string> $requiredPermissions Every listed permission is required
     */
    public function __construct(
        private readonly AuthenticationInterface $auth,
        private readonly string $loginPath = '/login',
        private readonly array $requiredRoles = [],
        private readonly array $requiredPermissions = [],
        ?AuthorizationInterface $authorization = null,
    ) {
        $this->authorization = $authorization ?? new Authorization(new PermissionRegistry());
    }

    public function handle(RequestInterface $request, callable $next): ResponseInterface
    {
        $identity = $this->auth->user();
        if ($identity === null) {
            return new RedirectResponse($this->loginPath);
        }

        if ($this->requiredRoles !== [] && !$this->authorization->hasAnyRole($identity, $this->requiredRoles)) {
            return new Response('Forbidden', 403);
        }

        foreach ($this->requiredPermissions as $permission) {
            if (!$this->authorization->isGranted($identity, $permission)) {
                return new Response('Forbidden', 403);
            }
        }

        return $next($request);
    }
}
