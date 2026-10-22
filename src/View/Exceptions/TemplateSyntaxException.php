<?php

declare(strict_types=1);

namespace Zara\View\Exceptions;

use RuntimeException;
use Throwable;
use Zara\View\Interfaces\TemplateExceptionInterface;

/**
 * Thrown by TemplateSyntaxValidator when a template tag is malformed
 * (an unclosed opener or an orphaned %}/!!}/}} closer).
 *
 * It deliberately does not extend TemplateException: during compilation,
 * TemplateCompiler does not know the source path. TemplateEngine::render()
 * catches it and wraps it in a TemplateException with the resolved path,
 * preserving this template line instead of the validator's PHP line.
 */
final class TemplateSyntaxException extends RuntimeException implements TemplateExceptionInterface
{
    public function __construct(
        string $message,
        private readonly int $templateLine,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getTemplateFile(): string
    {
        // The source path is not known here; TemplateEngine::render() adds it
        // when it wraps this exception in a TemplateException.
        return '';
    }

    public function getTemplateLine(): int
    {
        return $this->templateLine;
    }
}
