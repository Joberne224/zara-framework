<?php
declare(strict_types=1);

namespace Zara\View\Parser;

use Zara\View\Parser\Support\VariableExpressionResolver;

/**
 * Parser for variable declarations in templates.
 *
 * Handles: {% var name = value %}, arithmetic operators (+, -, ×, ÷, %),
 * and combined assignments (+=, -=, .=).
 *
 * Fix applied: this parser used to have three separate hand-built
 * branches (array literal, numeric literal, string literal all returned
 * raw; a fourth branch for "plain variable / single-level dot access"
 * hardcoded `isset($var['key']) ? $var['key'] : null`) alongside a fifth
 * "complex expression" branch that correctly called
 * VariableExpressionResolver::resolve(). Only the last branch was ever
 * kept in sync with resolve()'s evolution (magic-constant guarding,
 * AttributeAccessor dispatch, chained `.` access) — the dot-access
 * branch above it kept compiling straight to array access even once
 * `resolve()` learned to dispatch through AttributeAccessor for objects,
 * and only recognized ONE `.` level, so paradoxically `{% var x =
 * user.name.city %}` (two levels) worked correctly via the complex-
 * expression fallback while `{% var x = user.name %}` (one level, meant
 * to be the simple case) stayed broken.
 *
 * Removed rather than resynchronized by hand: every value now goes
 * through resolve() unconditionally, a single source of truth already
 * proven to correctly pass through numeric and array literals unchanged
 * (neither digits nor brackets/commas are identifier characters, so
 * resolve()'s scanner copies them as-is) and to protect string literals
 * via consumeStringLiteral(). A side benefit: identifiers or `.` chains
 * nested inside an array literal (`{% var x = [user.name, 'y'] %}`) are
 * now also correctly resolved — the old array-literal branch used to
 * return such expressions completely untouched.
 */
final class VariableDeclarationParser implements ParserInterface
{
    private VariableExpressionResolver $variables;

    public function __construct(?VariableExpressionResolver $variables = null)
    {
        $this->variables = $variables ?? new VariableExpressionResolver();
    }

    /**
     * {@inheritDoc}
     */
    public function parse(string $content): string
    {
        return preg_replace_callback(
            '/\{%\s*var\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\s*(\+=|-=|\/=|\*=|\.=|==|=)\s*(.+?)\s*%\}/s',
            function ($matches) {
                $variableName = $matches[1];
                $operator     = $matches[2];
                $value        = $this->variables->resolve(trim($matches[3]));

                return "<?php \${$variableName} {$operator} {$value}; ?>";
            },
            $content
        );
    }
}