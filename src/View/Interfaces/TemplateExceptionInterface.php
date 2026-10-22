<?php

declare(strict_types=1);

namespace Zara\View\Interfaces;

/**
 * Lets ErrorHandler retrieve a template's source file and line number
 * without depending on a specific template engine.
 */
interface TemplateExceptionInterface
{
    /**
     * Returns the path to the original template file (for example, /views/home.zara.php).
     */
    public function getTemplateFile(): string;

    /**
     * Returns the exact error line in the original source file.
     */
    public function getTemplateLine(): int;
}
