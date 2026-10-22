<?php
declare(strict_types=1);

namespace Zara\View\Parser;

use Zara\View\Parser\Support\VariableExpressionResolver;

/**
 * Parser for single-line multiple variable declarations.
 *
 * Handles: {% var x = 10, y = 20, title = "Zara" %}
 *
 * Fix applied: pair-splitting used to rely on a regex lookahead
 * (`(?=\s*,\s*[a-zA-Z_...]|$)`) that only checked for a comma followed by
 * an identifier-looking character — it had no idea a comma could be
 * sitting inside a quoted value. `{% var a = "x, y", b = 2 %}` split the
 * string literal in half at the internal comma, producing an
 * unterminated string and a broken second pair. Also, the initial
 * `str_contains($rawAssignments, ',')` fast-path check itself was
 * unreliable for the same reason: a single assignment whose value merely
 * contains a comma inside a string (`{% var msg = "a, b" %}`) would still
 * trigger this parser's multi-assignment path incorrectly. Both replaced
 * by VariableExpressionResolver::splitTopLevelAssignments(), which walks
 * the expression respecting string-literal and bracket/paren boundaries
 * — the real segment count decides whether this is a single or multiple
 * declaration, not a naive substring check.
 */
final class MultipleVariableDeclarationParser implements ParserInterface
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
            '/\{%\s*var\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*\s*=.*?)\s*%\}/s',
            function ($matches) {
                $segments = $this->variables->splitTopLevelAssignments($matches[1]);

                if (count($segments) < 2) {
                    // Single assignment: let VariableDeclarationParser handle it (SRP).
                    return $matches[0];
                }

                $phpCode = "<?php ";
                foreach ($segments as $segment) {
                    [$varName, $rawValue] = array_map('trim', explode('=', $segment, 2));
                    $value = $this->variables->resolve($rawValue);
                    $phpCode .= "\${$varName} = {$value}; ";
                }
                $phpCode .= "?>";

                return $phpCode;
            },
            $content
        );
    }
}