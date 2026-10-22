<?php
declare(strict_types=1);

namespace Zara\View\Parser;

use Zara\View\Parser\Support\VariableExpressionResolver;

/**
 * Parser for RAW template tags.
 *
 * Handles unescaped HTML output: {!! variable !!}, {!! post.title !!}
 *
 * Fix applied: \App\Core\RawHtml → \Zara\View\RawHtml
 * (namespace consistency with the Zara project).
 *
 * Fix applied (reported bug): the previous implementation used
 * preg_replace() with raw backreferences ($1, $2) directly in the
 * replacement string, without ever prefixing them with a $ to turn them
 * into real PHP variables — {!! text !!} compiled to isset(text[''])
 * ("text" read as an undefined constant, broken syntax) instead of
 * isset($text['']). RawParser is rewritten with preg_replace_callback(),
 * like every other parser in this engine, which builds its PHP through
 * normal string interpolation instead of raw regex backreferences —
 * precisely to avoid this kind of oversight.
 *
 * Fix applied (same family as the bugs already fixed in
 * VariableParser/ControlStructureParser/VariableDeclarationParser):
 * `.key` access was limited to a single level and hardcoded as array
 * access (isset($1['$2']) ? $1['$2'] : $1) — broken on an object
 * (isset() on an object without ArrayAccess doesn't throw, but silently
 * falls back to the ENTIRE variable instead of the requested field) and
 * impossible to chain ({!! post.author.name !!}). The expression now
 * goes entirely through VariableExpressionResolver::resolve(), shared
 * with every other parser, which routes access through AttributeAccessor
 * (array key, object property, or getter, decided at runtime, with an
 * arbitrary-length `.` chain and a loud error on a missing member).
 *
 * Fix applied: the resolved expression used to be repeated THREE times
 * in the original ternary (once per branch). Harmless for a plain
 * variable, but if the expression involves a method call (.method(args),
 * via AttributeAccessor::call()), the method would have been invoked
 * three times — an implicit side effect this engine consistently avoids
 * elsewhere (see AttributeAccessor's docblock on the deliberately
 * explicit nature of call()). The expression is now evaluated ONCE into
 * a temporary variable ($__zaraRaw), unset immediately after use.
 */
final class RawParser implements ParserInterface
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
            '/\{!!\s*(.+?)\s*!!\}/',
            function ($matches) {
                $phpValue = $this->variables->resolve(trim($matches[1]));

                return "<?php \$__zaraRaw = {$phpValue}; "
                    . "echo \\Zara\\View\\Runtime\\Escaper::escape(\$__zaraRaw); "
                    . "unset(\$__zaraRaw); ?>";
            },
            $content
        );
    }
}
