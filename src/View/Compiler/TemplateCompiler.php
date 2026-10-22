<?php
declare(strict_types=1);

namespace Zara\View\Compiler;

use Zara\View\Parser\ParserInterface;

/**
 * Orchestrates the template compilation pipeline.
 *
 * Parsers run in the order in which they are added with addParser().
 * TemplateEngine::createCompiler() preserves the order of the original
 * parseTemplate() pipeline.
 */
final class TemplateCompiler
{
    public const CACHE_VERSION = '2';

    /** @var ParserInterface[] */
    private array $parsers = [];

    public function addParser(ParserInterface $parser): void
    {
        $this->parsers[] = $parser;
    }

    public function compile(string $content): string
    {
        foreach ($this->parsers as $parser) {
            $content = $parser->parse($content);
        }

        return $content;
    }
}
