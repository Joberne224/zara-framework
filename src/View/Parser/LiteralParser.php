<?php
declare(strict_types=1);

namespace Zara\View\Parser;

/**
 * Parser for template literals.
 *
 * Extracted from TemplateEngine::parseLiteral().
 *
 * Handles: {{ 'string' }}, {{ "string" }}, {{ 123 }}
 */
final class LiteralParser implements ParserInterface
{
    /**
     * {@inheritDoc}
     */
    public function parse(string $content): string
    {
        return preg_replace_callback(
            '/\b\{\{\s*(\'[^\']*\'|"[^"]*"|\d+)\s*\}\}/',
            function ($matches) {
                return "<?php echo {$matches[1]}; ?>";
            },
            $content
        );
    }
}
