<?php

namespace Zara\Core\Http;

use Zara\Core\Routing\Interfaces\RouteMatchInterface;
use Zara\Container\Interfaces\MethodInjectorInterface;
use Zara\Controller\ControllerFactoryInterface;
use Zara\Core\Http\Interfaces\ResponseInterface;
use Zara\Core\Http\Interfaces\ResponseBuilderInterface;
use Zara\Core\Http\Exceptions\InvalidControllerResponseException;
use Zara\Core\Http\Exceptions\UnresolvableRouteHandlerException;


final class ResponseBuilder implements ResponseBuilderInterface
{
    public function __construct(
        private ControllerFactoryInterface $controllerFactory,
        private MethodInjectorInterface    $methodInjector,
    ) {}
    
    public function handle(RouteMatchInterface $routeMatch, array $params): ResponseInterface
    {
        $callable = $routeMatch->hasController()
            ? [$this->controllerFactory->make($routeMatch->getClass()), $routeMatch->getMethod()]
            : $routeMatch->getCallable();

        if (!is_callable($callable)) {
            throw new UnresolvableRouteHandlerException(
                'Route match did not resolve to a callable handler.'
            );
        }

        $response = $this->methodInjector->invoke($callable, $params);

        if (!$response instanceof ResponseInterface) {
            throw new InvalidControllerResponseException(
                'Controller action must return a ' . ResponseInterface::class . ' instance, got '
                . (is_object($response) ? $response::class : gettype($response)) . '.'
            );
        }

        if (!$response->getHeader('Content-Type')) {
            $response = $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        return $response;
    }   
}