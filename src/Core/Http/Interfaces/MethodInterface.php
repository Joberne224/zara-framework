<?php
declare(strict_types=1);

namespace Zara\Core\Http\Interfaces;

interface MethodInterface
{
    public function getName(): string;
    
    public function isGet(): bool;
    public function isPost(): bool;
    public function isPut(): bool;
    public function isDelete(): bool;
    public function isSafe(): bool;
    public function isIdempotent(): bool;

    public function __toString(): string;
}   