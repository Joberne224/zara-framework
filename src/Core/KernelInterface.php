<?php
// file: Zara/Core
declare(strict_types=1);

namespace Zara\Core;

use Zara\Core\Http\Interfaces\RequestInterface;

interface KernelInterface
{
    public function run(RequestInterface $request): void;
}