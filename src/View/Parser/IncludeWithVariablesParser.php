<?php
declare(strict_types=1);

namespace Zara\View\Parser;

use Zara\View\Parser\Support\VariableExpressionResolver;
use Zara\View\Engine\TemplateResolver;
use Zara\View\Parser\Support\TemplateInclusionStack;

/**
 * Parser for including sub-templates with isolated local variable injection.
 *
 * Handles: {% include 'partials/button' with text = "Sign up", color = "blue" %}
 */
final class IncludeWithVariablesParser implements ParserInterface
{
    private TemplateSyntaxValidator $syntaxValidator;
    private VariableExpressionResolver $variables;
    private TemplateInclusionStack $stack;

    public function __construct(
        private TemplateResolver $resolver,
        ?TemplateSyntaxValidator $syntaxValidator = null,
        ?VariableExpressionResolver $variables = null,
        ?TemplateInclusionStack $stack = null
    ) {
        $this->syntaxValidator = $syntaxValidator ?? new TemplateSyntaxValidator();
        $this->variables = $variables ?? new VariableExpressionResolver();
        $this->stack = $stack ?? new TemplateInclusionStack();
    }

    /**
     * {@inheritDoc}
     */
    public function parse(string $content): string
    {
        return preg_replace_callback(
            '/\{%\s*include\s+[\'"](.+?)[\'"]\s+with\s+(.+?)\s*%\}/',
            function ($matches) {
                $templateName = $matches[1];
                $rawVariables = $matches[2];
                $templatePath = $this->resolver->resolveTemplatePath($templateName);

                if (!file_exists($templatePath)) {
                    throw new \RuntimeException("Included template '$templateName' not found.");
                }

                $this->stack->push($templatePath);

                try {
                    $rawContent = file_get_contents($templatePath);
                    $includeContent = $this->syntaxValidator->parse($rawContent);

                    // Recursively resolve nested includes inside the loaded partial
                    $includeContent = $this->parse($includeContent);

                    $segments = $this->variables->splitTopLevelAssignments($rawVariables);

                    $phpBefore = "<?php \$__zaraBackup = []; ";
                    $phpAssign = "";

                    foreach ($segments as $segment) {
                        [$varName, $rawValue] = array_map('trim', explode('=', $segment, 2));
                        $value = $this->variables->resolve($rawValue);

                        $phpBefore .= "if (isset(\${$varName})) { \$__zaraBackup['{$varName}'] = \${$varName}; } ";
                        $phpAssign .= "\${$varName} = {$value}; ";
                    }
                    $phpBefore .= $phpAssign . "?>";

                    $phpAfter = "<?php foreach (\$__zaraBackup as \$__k => \$__v) { \$\$__k = \$__v; } ?>";

                    return $phpBefore . $includeContent . $phpAfter;
                } finally {
                    $this->stack->pop();
                }
            },
            $content
        );
    }
}
