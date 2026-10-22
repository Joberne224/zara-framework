<?php

namespace Zara\Core\Http\Interfaces;

use Zara\Core\Routing\RouteMatch;

interface ResponseBuilderInterface
{
    public function handle(RouteMatch $routeMatch, array $params): ResponseInterface;
}