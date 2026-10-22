<?php
declare(strict_types=1);

namespace Zara\Core;

use Zara\Core\Http\Interfaces\DispatcherInterface;
use Zara\Container\Interfaces\RequestBinderInterface;
use Zara\Core\Debug\Profiler\Panel\Interfaces\RoutingPanelInterface;
use Zara\Core\Debug\Profiler\Panel\NullRoutingPanel;
use Zara\Core\Routing\Interfaces\RouterInterface;
use Zara\Core\Http\Interfaces\ResponseBuilderInterface;

final class KernelServices
{
    public function __construct(
        public readonly RouterInterface          $router,
        public readonly DispatcherInterface      $dispatcher,
        public readonly ResponseBuilderInterface $responseBuilder,
        public readonly RequestBinderInterface   $requestBinder,
        public readonly RoutingPanelInterface    $routingPanel = new NullRoutingPanel(),
    ) {}
}