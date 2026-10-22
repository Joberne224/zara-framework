<?php

declare(strict_types=1);

namespace Zara\Core\Http;

use Closure;
use Zara\Core\Http\Interfaces\DispatcherInterface;
use Zara\Core\Support\Interfaces\PipelineInterface;
use Zara\Core\Http\Interfaces\RequestInterface;
use Zara\Core\Http\Interfaces\ResponseInterface;
use Zara\Core\Routing\Interfaces\RouterInterface;

/**
 * Dispatches HTTP requests through global and route-specific middleware stacks.
 */
final class Dispatcher implements DispatcherInterface
{   
    public function __construct(
        private readonly RouterInterface   $router,
        private readonly PipelineInterface $pipeline
    ) {}

    /**
     * Dispatches the HTTP request through combined global and route middlewares.
     */
    public function dispatch(array $middlewares, Closure $handler, RequestInterface $request): ResponseInterface
    {
        $pipeline = array_merge(
            $this->router->getGlobalMiddlewares(),
            $middlewares
        );

        return $this->pipeline
            ->send($request)
            ->through($pipeline)
            ->then($handler);
    }
}