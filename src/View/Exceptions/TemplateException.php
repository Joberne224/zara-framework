<?php

declare(strict_types=1);

namespace Zara\View\Exceptions;

use Zara\View\Interfaces\TemplateExceptionInterface;

/**
 * Exception thrown when a template has a syntax or runtime error.
 */
class TemplateException extends \Exception implements TemplateExceptionInterface
{
    public function __construct(
        string $message,
        private readonly string $templateFile,
        private readonly int $templateLine,
        ?\Throwable $previous = null
    ) {
        // Preserve the original PHP error or exception as the previous exception.
        parent::__construct($message, 0, $previous);
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
