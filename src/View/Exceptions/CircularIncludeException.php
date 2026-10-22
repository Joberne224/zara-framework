<?php

declare(strict_types=1);

namespace Zara\View\Exceptions;

use RuntimeException;
use Zara\View\Interfaces\TemplateExceptionInterface;

/**
 * Raised by TemplateInclusionStack when {% include %} or {% extends %}
 * would resolve back to a template already being compiled.
 *
 * Mirrors TemplateSyntaxException: the final template path is not yet
 * known at this stage of the pipeline, so this implements
 * TemplateExceptionInterface directly rather than extending
 * TemplateException. TemplateEngine::render() catches and rewraps it
 * with the correct templateFile, same as it already does for
 * TemplateSyntaxException.
 */
final class CircularIncludeException extends RuntimeException implements TemplateExceptionInterface
{
    private string $templateFile = '';

    private function __construct(
        string $message,
        private readonly int $templateLine,
    ) {
        parent::__construct($message);
    }

    /**
     * @param string[] $chain Ordered list of template paths, ending with
     *                        the path that closes the cycle (i.e. the
     *                        same path appears twice in the array).
     */
    public static function forChain(array $chain, int $templateLine = 0): self
    {
        return new self(
            sprintf('Circular template reference detected: %s', implode(' -> ', $chain)),
            $templateLine,
        );
    }

    public function getTemplateFile(): string
    {
        return $this->templateFile;
    }

    public function getTemplateLine(): int
    {
        return $this->templateLine;
    }
}