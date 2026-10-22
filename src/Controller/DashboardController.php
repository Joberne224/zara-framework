<?php
declare(strict_types=1);

namespace Zara\Dashboard\Controller;

use Zara\Core\Http\Interfaces\ResponseInterface;
use Zara\Core\Http\JsonResponse;
use Zara\Core\Routing\Router;

final class DashboardController
{
    public function __construct(
        private readonly Router $router
    ) {}

    /**
     * Returns the framework health status for monitoring.
     */
    public function index(): ResponseInterface
    {
        return new JsonResponse([
            'status' => 'operational',
            'cache' => [
                'has_cache_system' => $this->router->hasCache(),
                'is_cached' => $this->router->isCached(),
            ],
            'routing' => [
                'total_routes' => count($this->router->getRoutes()),
            ]
        ]);
    }
}
