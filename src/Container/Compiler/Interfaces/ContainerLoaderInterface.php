<?php
declare(strict_types=1);

namespace Zara\Container\Compiler\Interfaces;

use Zara\Container\ParameterBag;
use Zara\Container\Compiler\CompiledFactoryInterface;

interface ContainerLoaderInterface
{
    public function load(string $compiledPath, int $sourceMtime, ParameterBag $parameters, ?string $expectedFingerprint = null): CompiledFactoryInterface;
}
