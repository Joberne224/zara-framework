<?php
declare(strict_types=1);

namespace Zara\View\Engine;

use RuntimeException;
use Throwable;
use Zara\View\Engine\TemplateResolver;
use Zara\View\Interfaces\TemplateEngineInterface;

/**
 * Native PHP template engine using output buffering with strict path resolution and isolated scope.
 * PHP templates are executable application code and must only come from trusted sources;
 * this engine intentionally does not apply the Zara DSL's output escaping or method guard.
 */
final class PhpEngine implements TemplateEngineInterface
{
    public function __construct(
        private readonly TemplateResolver $resolver
    ) {}

    public function render(string $template, array $data = []): string
    {
        $templatePath = $this->resolver->resolveTemplatePath($template);

        if (!is_file($templatePath)) {
            throw new RuntimeException(sprintf("Template file not found or invalid: '%s'.", $templatePath));
        }
        $initialObLevel = ob_get_level();
        ob_start();

        try {

            (static function (): void {
                extract(func_get_arg(1), EXTR_SKIP);
                require func_get_arg(0);
            })($templatePath, $data);

            return (string) ob_get_clean();
        } catch (Throwable $e) {
            while (ob_get_level() > $initialObLevel) {
                ob_end_clean();
            }

            throw $e;
        }
    }
}
