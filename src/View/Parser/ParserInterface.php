<?php
declare(strict_types=1);

namespace Zara\View\Parser;

interface ParserInterface
{
    public function parse(string $content): string;
}