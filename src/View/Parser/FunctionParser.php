<?php
declare(strict_types=1);

namespace Zara\View\Parser;

use Zara\View\Registry\FunctionRegistry;
use Zara\View\Parser\Support\VariableExpressionResolver;

/**
 * Parser for function calls in templates.
 *
 * Extracted from TemplateEngine::parseFunctions().
 *
 * Handles: {{ my_function(arg1, arg2) }}
 *
 * Behavior depends on the type of function:
 *   - Registered callables (such as url and asset) are invoked through
 *     variables injected into the render scope.
 *   - Allowed native PHP functions (such as count and empty) are called directly.
 *
 * Raw arguments captured by the regular expression are resolved through
 * VariableExpressionResolver before being inserted into compiled PHP, so
 * bare identifiers such as `title` become template variables (`$title`).
 */
final class FunctionParser implements ParserInterface
{
    private VariableExpressionResolver $variables;

    public function __construct(
        private FunctionRegistry $functions,
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
            '/{{\s*([a-zA-Z_][a-zA-Z0-9_]*)\((.*?)\)\s*}}/',
            function ($matches) {
                $functionName = trim($matches[1]);
                $arguments    = $this->variables->resolve(trim($matches[2]));

                if ($this->functions->isCallable($functionName)) {
                    // The callable is injected as a variable into the render scope.
                    return "<?php echo \Zara\View\Runtime\Escaper::escape(\${$functionName}({$arguments})); ?>";
                }

                if ($this->functions->isAllowed($functionName)) {
                    // Native PHP function called directly.
                    return "<?php echo \Zara\View\Runtime\Escaper::escape({$functionName}({$arguments})); ?>";
                }

                throw new \BadFunctionCallException(
                    "Function call '{$functionName}' is not allowed in templates."
                );
            },
            $content
        );
    }
}
