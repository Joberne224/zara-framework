<?php
declare(strict_types=1);

namespace Zara\Core;

use Monolog\Handler\Handler;
use Zara\Core\Http\Interfaces\RequestInterface;
use Zara\Core\Http\Interfaces\ResponseInterface;
use Zara\Core\Http\Interfaces\DispatcherInterface;
use Zara\Core\KernelInterface;
use Zara\Core\Http\Interfaces\ResponseBuilderInterface;
use Zara\Core\Debug\Profiler\Panel\Interfaces\RoutingPanelInterface;
use Zara\Core\Routing\Interfaces\RouterInterface;
use Zara\Container\Interfaces\RequestBinderInterface;
use Zara\Core\Routing\Exceptions\RouteNotFoundException;
use Zara\Core\Routing\Exceptions\RequestMethodNotFoundException;
use Zara\Core\Error\HttpException\NotFoundException;
use Zara\Core\Error\HttpException\MethodNotAllowedException;

final class Kernel implements KernelInterface
{
    private RouterInterface          $router;
    private RoutingPanelInterface    $routingPanel;
    private ResponseBuilderInterface $responseBuilder;
    private DispatcherInterface      $dispatcher;
    private RequestBinderInterface   $requestBinder;

    public function __construct(KernelServices $config)
    {  
        $this->router          = $config->router;
        $this->dispatcher      = $config->dispatcher;
        $this->routingPanel    = $config->routingPanel;
        $this->responseBuilder = $config->responseBuilder;
        $this->requestBinder   = $config->requestBinder;
    }

    public function run(RequestInterface $request): void
    {
        try {
            $routeMatch = $this->router->match($request);

            // Request is immutable: withAttribute() below returns a NEW
            // instance. The synthetic RequestInterface service registered
            // in index.php still points to the pre-match instance, so it
            // must be explicitly republished — otherwise any controller
            // parameter type-hinted RequestInterface (autowired through the
            // container rather than passed via route params) resolves to a
            // stale Request with no '_route' attribute.
            $request = $request->withAttribute('_route', $routeMatch->getName());
            $this->requestBinder->rebindRequest($request);

            $middlewares = $routeMatch->getMiddlewares();
            $params = $routeMatch->getParams();
           
            $this->routingPanel->setMatchedRoute(
                $request->getUri()->getPath(),
                $routeMatch->getCallable(),
                $routeMatch->getParams(),
                $routeMatch->getMiddlewares()
            );

            $handler = fn (): ResponseInterface => $this->responseBuilder->handle($routeMatch, $params);
            $response = $this->dispatcher->dispatch($middlewares, $handler, $request);

            $response->send();

        } catch(RouteNotFoundException $e) {
            throw new NotFoundException($e->getMessage(), $e);
        } catch(RequestMethodNotFoundException $e) {
            throw new MethodNotAllowedException(
                $e->getAllowedMethods(),
                $e->getMessage(),
                $e
            );
        }
    }
}