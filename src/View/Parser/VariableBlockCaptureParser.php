<?php
declare(strict_types=1);

namespace Zara\View\Parser;

/**
 * Parser for capturing HTML blocks into template variables.
 *
 * Handles:
 *   {% var myButton %}
 *       <button>...</button>
 *   {% endvar %}
 * 
 * Captured output is wrapped in RawHtml to mark it as trusted markup created
 * by the template and prevent it from being escaped again during rendering.
 */
final class VariableBlockCaptureParser implements ParserInterface
{
    /**
     * {@inheritDoc}
     */
    public function parse(string $content): string
    {
        return preg_replace_callback(
            '/\{%\s*var\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*%\}(.*?)\{%\s*endvar\s*%\}/s',
            function ($matches) {
                $variableName = $matches[1];
                $blockContent = $matches[2];

                // Capture the block output in an output buffer.
                $phpBefore = "<?php ob_start(); ?>";
                
                // Wrap the captured markup in the trusted RawHtml type.
                $phpAfter  = "<?php \${$variableName} = new \Zara\View\RawHtml(ob_get_clean()); ?>";

                return $phpBefore . $blockContent . $phpAfter;
            },
            $content
        );
    }
}
