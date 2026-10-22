<?php
declare(strict_types=1);

namespace Zara\View\Interfaces;

interface TemplateEngineInterface
{
    public function render(string $template, array $data = []): string;
}