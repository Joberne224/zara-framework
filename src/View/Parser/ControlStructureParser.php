<?php
declare(strict_types=1);

namespace Zara\View\Parser;

use Zara\View\Parser\Support\VariableExpressionResolver;

/**
 * Parser for template control structures.
 *
 * Extracted faithfully from TemplateEngine:
 *   parseWith(), parseIf(), parseElseIf(), parseElse(), parseFor()
 *   and the direct endif/endfor/endwith replacements.
 *
 * Execution order in parse() matches parseTemplate()'s pipeline:
 * with → if → elseif → else → endif → for → endfor.
 *
 * Fix applied: transformCondition() now delegates to
 * VariableExpressionResolver for var.key -> AttributeAccessor conversion
 * and bare-identifier prefixing, instead of duplicating that logic
 * locally.
 *
 * Fix applied (this pass): parseWith()/endwith used to be two
 * independent regex passes — endwith compiled to a HARDCODED
 * `unset($alias)`, unsetting a literal variable named $alias instead of
 * whichever alias name with() actually captured. The dynamic alias
 * leaked (stayed set after the block) and, worse, would destroy an
 * unrelated real $alias variable if one existed. Fixed by merging
 * with/endwith into a single preg_replace_callback pass using a stack
 * ($withStack): with() pushes the alias it just captured, endwith() pops
 * it and unsets exactly that name — also correctly handles nested
 * {% with %} blocks. An orphan {% endwith %} now throws, rather than
 * silently becoming a no-op — consistent with this engine's "loud
 * errors" preference elsewhere.
 *
 * Fix applied (this pass): parseWith()'s value expression used to be
 * built directly from its own regex groups
 * (`isset($var['key']) ? $var['key'] : $var`) — bypassing
 * VariableExpressionResolver/AttributeAccessor entirely, so `{% with
 * user.name as x %}` fataled at render time the moment `user` turned out
 * to be an object (`Cannot use object of type X as array`). It also only
 * ever recognized a single `.key` level, and its "key missing" fallback
 * silently aliased the ENTIRE base variable rather than null. The
 * expression is now free-form (`.+?`) and resolved through
 * VariableExpressionResolver::resolve(), with arbitrary chaining and
 * correct array/object dispatch via AttributeAccessor.
 *
 * Fix applied (this pass): parseFor()'s collection source only ever
 * matched a single bare identifier, making `{% for post in user.posts %}`
 * impossible — the tag simply didn't match and was left in the output as
 * literal, broken text. The source expression is now free-form (`.+?`)
 * and resolved through VariableExpressionResolver::resolve().
 *
 * Fix applied (this pass): transformCondition() ran two blind
 * substitutions on the raw condition text, both BEFORE resolve() ever
 * saw it and so both blind to string-literal boundaries: a ×/÷ strtr(),
 * and, after resolve(), a str_replace of ' and '/' or ' to '&&'/'||'. A
 * literal string containing "×", "÷", " and ", or " or " as ordinary
 * text — e.g. `{% if user.name == "John and Jane" %}` — was silently
 * corrupted. ×/÷ translation now lives inside
 * VariableExpressionResolver::resolve() itself (see its own docblock).
 * The and/or replacement is removed entirely: RESERVED_WORDS already
 * leaves a bare `and`/`or` untouched as a valid PHP keyword, so no
 * translation was ever actually necessary once resolve() correctly
 * protects literals.
 */
final class ControlStructureParser implements ParserInterface
{
    private VariableExpressionResolver $variables;

    /** @var list<string> */
    private array $withStack = [];

    public function __construct(?VariableExpressionResolver $variables = null)
    {
        $this->variables = $variables ?? new VariableExpressionResolver();
    }

    /**
     * {@inheritDoc}
     */
    public function parse(string $content): string
    {
        $content = $this->parseWithAndEndWith($content);

        $content = $this->parseIf($content);
        $content = $this->parseElseIf($content);
        $content = preg_replace('/\{%\s*endif\s*%\}/', '<?php endif; ?>', $content);
        $content = $this->parseElse($content);

        $content = $this->parseFor($content);
        $content = preg_replace('/\{%\s*endfor\s*%\}/', '<?php endforeach; ?>', $content);

        return $content;
    }

    /**
     * Parses with/endwith together via a stack, so endwith always unsets
     * the exact alias with() captured (see class docblock). $withStack
     * is reset at the start of every parse() call.
     */
    private function parseWithAndEndWith(string $content): string
    {
        $this->withStack = [];

        return preg_replace_callback(
            '/\{%\s*(?:with\s+(.+?)\s+as\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)|endwith)\s*%\}/s',
            function ($matches) {
                $isWith = isset($matches[2]) && $matches[2] !== '';

                if ($isWith) {
                    $value = $this->variables->resolve($matches[1]);
                    $alias = $matches[2];

                    $this->withStack[] = $alias;

                    return "<?php \${$alias} = {$value}; ?>";
                }

                if ($this->withStack === []) {
                    throw new \RuntimeException(
                        'Orphan {% endwith %} found with no matching {% with ... as ... %} before it.'
                    );
                }

                $alias = array_pop($this->withStack);

                return "<?php unset(\${$alias}); ?>";
            },
            $content
        );
    }

    private function parseIf(string $content): string
    {
        return preg_replace_callback(
            '/\{%\s*if\s+(.+?)\s*%\}/s',
            function ($matches) {
                $condition = $this->transformCondition($matches[1]);
                return "<?php if ($condition): ?>";
            },
            $content
        );
    }

    private function parseElseIf(string $content): string
    {
        return preg_replace_callback(
            '/\{%\s*elseif\s+(.+?)\s*%\}/s',
            function ($matches) {
                $condition = $this->transformCondition($matches[1]);
                return "<?php elseif ($condition): ?>";
            },
            $content
        );
    }

    private function parseElse(string $content): string
    {
        return preg_replace('/\{%\s*else\s*%\}/', '<?php else: ?>', $content);
    }

    /**
     * The collection source is a free-form expression (e.g. `user.posts`,
     * not just a bare identifier), resolved the same way as everywhere
     * else in this engine.
     */
    private function parseFor(string $content): string
    {
        return preg_replace_callback(
            '/\{%\s*for\s+([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)(?:,\s*([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*))?\s+in\s+(.+?)\s*%\}/s',
            function ($matches) {
                $first  = $matches[1];
                $second = $matches[2] ?? null;
                $source = $this->variables->resolve($matches[3]);

                if ($second) {
                    return "<?php foreach ((array){$source} as \${$first} => \${$second}): ?>";
                }
                return "<?php foreach ((array){$source} as \${$first}): ?>";
            },
            $content
        );
    }

    private function transformCondition(string $condition): string
    {
        return $this->variables->resolve(trim($condition));
    }
}