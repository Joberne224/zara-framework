<?php
declare(strict_types=1);

namespace Zara\View\Parser;

use Zara\View\Registry\FilterRegistry;
use Zara\View\Parser\Support\VariableExpressionResolver;

/**
 * Parser for variables and filters in templates.
 *
 * Extracted faithfully from TemplateEngine::parseVariable().
 *
 * Handles:
 *   {{ variable }}
 *   {{ variable | filter }}
 *   {{ variable | filter1 | filter2 }}
 *   {{ user.name | escape }}
 *   {{ x + y }}, {{ prix * 1.20 }}, {{ user.age + 1 }}
 *
 * Protects PHP magic constants.
 *
 * Fix applied: this parser used to have its own dedicated branch for the
 * "plain variable / dot chain, no operators" case (`user.name`), which
 * hardcoded array access with an isset-safe null fallback
 * (`isset($var['key']) ? $var['key'] : null`) directly here — a second,
 * separate implementation of `.` sitting next to
 * VariableExpressionResolver::consumeDotChain(), which routes the exact
 * same syntax through Zara\View\Runtime\AttributeAccessor (array key,
 * object property, or getter, decided at render time). Only the general
 * branch below was ever updated to call the resolver; this dedicated one
 * kept compiling `user.name` straight to array access even once `user`
 * was an object — silently wrong instead of throwing. Removed rather
 * than kept in sync by hand: every non-literal expression now goes
 * through resolve(), a single source of truth for `.`/`->`/calls.
 * Behavioral note: a missing array key or object member now throws
 * (AttributeAccessor's deliberate "loud error" design) instead of
 * silently yielding null as the old isset-safe branch did.
 *
 * Fix applied: the "general expression" branch (anything that is
 * neither a literal, nor a variable/array access) used to copy the raw
 * expression as-is — {{ x + y }} produced <?php echo x + y; ?>, where x
 * and y are read by PHP as undefined constants instead of the variables
 * $x and $y. This branch now delegates to VariableExpressionResolver,
 * shared with FunctionParser, ControlStructureParser and
 * VariableDeclarationParser, which correctly prefixes bare identifiers
 * without touching strings, numbers, or function calls.
 *
 * Fix applied: this parser used to duplicate its own forbidden-
 * superglobals list, redundant with — and inconsistent with —
 * CompiledCodeInspector's (_SESSION protected here but not there, GLOBALS
 * the other way around). The check also compared the raw expression to
 * '$' . superglobal, so it only ever blocked the explicit form
 * ({{ $_GET }}), never the bare form ({{ _GET }}) that is the only
 * syntax consistent with the rest of this DSL (every variable is written
 * without $): {{ _GET }} passed through this parser untouched and came
 * out as <?php echo $_GET; ?>. And when the explicit form was caught, it
 * was silently dropped (empty string), without warning the developer —
 * and made CompiledCodeInspector's own check unreachable for that case.
 * Superglobal detection is now solely CompiledCodeInspector's
 * responsibility, performed via PHP token analysis on the final compiled
 * code (so insensitive to the original template syntax) and failing
 * loudly through an explicit RuntimeException instead of silence.
 *
 * Fix applied: magic-constant blocking used to compare the ENTIRE
 * expression against a list — {{ __FILE__ }} alone was blocked, but
 * {{ __FILE__.suffix }}, {{ __FILE__ . 'x' }} or {{ __LINE__ + 1 }}
 * passed through intact, since only a sub-part of the expression is ever
 * a magic constant, never the whole expression. Once buried in a larger
 * expression, it traversed this parser as an ordinary bare identifier and
 * came out prefixed as $__FILE__ — a nonexistent template variable,
 * causing an "Undefined variable" error instead of blocking the server
 * information leak (path, line, class...) the block was meant to
 * prevent. Detection now happens per identifier rather than per whole
 * expression, inside VariableExpressionResolver/consumeDotChain(), which
 * every non-literal expression now goes through unconditionally.
 */
final class VariableParser implements ParserInterface
{
    private VariableExpressionResolver $variables;

    public function __construct(
        private FilterRegistry $filters,
        ?VariableExpressionResolver $variables = null
    ) {
        $this->variables = $variables ?? new VariableExpressionResolver();
    }

    /**
     * {@inheritDoc}
     */
    public function parse(string $content): string
    {
        return preg_replace_callback(
            '/{{\s*(.+?)\s*}}/',
            function ($matches) {
                $parts        = explode('|', trim($matches[1]));
                $variableExpr = trim(array_shift($parts));

                if (preg_match("/^(['\"])(.*)\\1$/", $variableExpr)) {
                    // String literal
                    $phpCode = $variableExpr;
                } elseif (is_numeric($variableExpr)) {
                    // Numeric literal
                    $phpCode = $variableExpr;
                } else {
                    // Everything else — plain variable, `.` member access
                    // (array/object, dispatched at runtime by
                    // AttributeAccessor), `->` access, calls, and full
                    // expressions (x + y, user.age + 1, ...) — all
                    // handled by the shared resolver.
                    $phpCode = $this->variables->resolve($variableExpr);
                }

                // Filter chaining
                foreach ($parts as $filterPart) {
                    $filterPart      = trim($filterPart);
                    $filter_and_args = explode(':', $filterPart, 2);
                    $filterName      = trim($filter_and_args[0]);

                    if (!$this->filters->has($filterName)) {
                        throw new \BadFunctionCallException(
                            "Filter '{$filterName}' is not allowed or does not exist."
                        );
                    }

                    $arguments = isset($filter_and_args[1])
                        ? ', ' . $this->variables->resolve(trim($filter_and_args[1]))
                        : '';
                    $phpCode   = "\${$filterName}({$phpCode}{$arguments})";
                }

                return "<?php echo \Zara\View\Runtime\Escaper::escape({$phpCode}); ?>";
            },
            $content
        );
    }
}
