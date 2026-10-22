<?php
declare(strict_types=1);

namespace Zara\View\Parser;

use Zara\View\Engine\TemplateResolver;
use Zara\View\Parser\Support\TemplateInclusionStack;

/**
 * Parser for template inheritance.
 *
 * Handles:
 *   - {% extends 'layout' %} or {% extends "layout" %}
 *   - {% block name %}...{% endblock %}
 *
 * Supports multi-level inheritance (extends chain) with circular reference protection
 * via TemplateInclusionStack, AND nested blocks (a {% block %} defined inside
 * another {% block %}, e.g. {% block content %} nested inside {% block body %}).
 */
final class BlockParser implements ParserInterface
{
    private TemplateSyntaxValidator $syntaxValidator;
    private TemplateInclusionStack $stack;

    public function __construct(
        private TemplateResolver $resolver,
        ?TemplateSyntaxValidator $syntaxValidator = null,
        ?TemplateInclusionStack $stack = null
    ) {
        $this->syntaxValidator = $syntaxValidator ?? new TemplateSyntaxValidator();
        $this->stack = $stack ?? new TemplateInclusionStack();
    }

    /**
     * {@inheritDoc}
     */
    public function parse(string $content): string
    {
        $blockCache = [];
        $pushedPaths = [];

        try {
            while (preg_match('/\{%\s*extends\s+[\'"](.+?)[\'"]\s*%\}/', trim($content), $matches)) {
                $parentTemplate     = $matches[1];
                $parentTemplatePath = $this->resolver->resolveTemplatePath($parentTemplate);

                if (!file_exists($parentTemplatePath)) {
                    throw new \RuntimeException(
                        "Parent template not found: $parentTemplatePath"
                    );
                }

                // Collect blocks from current template level (including nested ones).
                // Child blocks take precedence over parent/grandparent blocks.
                $this->collectBlocks($content, $blockCache);

                // Push parent path onto inclusion stack (throws CircularIncludeException on cycle)
                $this->stack->push($parentTemplatePath);
                $pushedPaths[] = $parentTemplatePath;

                // Load and validate parent template
                $rawParent = file_get_contents($parentTemplatePath);
                $content   = $this->syntaxValidator->parse($rawParent);
            }

            // Replace blocks in the root parent template with the cached overrides (or defaults),
            // resolving nested block tags recursively.
            return $this->injectBlocks($content, $blockCache);
        } finally {
            // Restore stack state for all parent paths pushed in this pass
            while (!empty($pushedPaths)) {
                array_pop($pushedPaths);
                $this->stack->pop();
            }
        }
    }

    /**
     * Walks $content collecting every {% block name %}...{% endblock %} pair —
     * including blocks nested inside other blocks — using depth-aware matching
     * instead of a non-greedy regex (which incorrectly stops at the FIRST
     * {% endblock %} it finds, even if that endblock actually closes a nested
     * block rather than the outer one).
     */
    private function collectBlocks(string $content, array &$blockCache): void
    {
        $openPattern = '/\{%\s*block\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*%\}/';
        $offset = 0;

        while (preg_match($openPattern, $content, $openMatch, PREG_OFFSET_CAPTURE, $offset)) {
            $blockName = $openMatch[1][0];
            $openEnd   = $openMatch[0][1] + strlen($openMatch[0][0]);

            $inner = $this->matchEndblock($content, $openEnd);

            if (!isset($blockCache[$blockName])) {
                $blockCache[$blockName] = $inner['content'];
            }

            // Recurse to also register any block(s) nested inside this one.
            $this->collectBlocks($inner['content'], $blockCache);

            $offset = $inner['end'];
        }
    }

    /**
     * Rebuilds $content, replacing every top-level {% block name %}...{% endblock %}
     * with the cached override (or the block's own default content when no
     * override exists). Recurses into the replacement text so that a block
     * nested inside another block gets resolved too, whether it came from
     * the override or from the default content.
     */
    private function injectBlocks(string $content, array $blockCache): string
    {
        $openPattern = '/\{%\s*block\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*%\}/';
        $result = '';
        $offset = 0;

        while (preg_match($openPattern, $content, $openMatch, PREG_OFFSET_CAPTURE, $offset)) {
            $blockName = $openMatch[1][0];
            $openStart = $openMatch[0][1];
            $openEnd   = $openStart + strlen($openMatch[0][0]);

            $result .= substr($content, $offset, $openStart - $offset);

            $inner       = $this->matchEndblock($content, $openEnd);
            $replacement = $blockCache[$blockName] ?? $inner['content'];

            $result .= $this->injectBlocks($replacement, $blockCache);

            $offset = $inner['end'];
        }

        $result .= substr($content, $offset);

        return $result;
    }

    /**
     * Starting right after an opening {% block name %} tag, scans forward
     * tracking nested {% block %} / {% endblock %} depth to find the ACTUAL
     * matching {% endblock %} for that opening tag.
     *
     * @return array{content: string, end: int} inner content and the offset
     *         just past the matching {% endblock %} tag.
     */
    private function matchEndblock(string $content, int $searchFrom): array
    {
        $tagPattern = '/\{%\s*(block\s+[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*|endblock)\s*%\}/';
        $depth  = 0;
        $offset = $searchFrom;

        while (preg_match($tagPattern, $content, $tagMatch, PREG_OFFSET_CAPTURE, $offset)) {
            $tagStart = $tagMatch[0][1];
            $tagEnd   = $tagStart + strlen($tagMatch[0][0]);
            $isEndblock = trim($tagMatch[1][0]) === 'endblock';

            if ($isEndblock) {
                if ($depth === 0) {
                    return [
                        'content' => substr($content, $searchFrom, $tagStart - $searchFrom),
                        'end'     => $tagEnd,
                    ];
                }
                $depth--;
            } else {
                $depth++;
            }

            $offset = $tagEnd;
        }

        throw new \RuntimeException(
            'Unmatched {% block %} tag: no corresponding {% endblock %} found.'
        );
    }
}