<?php

declare(strict_types=1);

namespace Zara\Core;

interface LazyServiceProxyInterface 
{
    public function getRealInstance(): object;
}