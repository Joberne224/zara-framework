<?php
declare(strict_types=1);

namespace Zara\View\Parser;

use Zara\View\Engine\TemplateResolver;
use Zara\View\Parser\Support\TemplateInclusionStack;
use Zara\View\Parser\Support\VariableExpressionResolver;

/**
 * Parser for template includes (with optional isolated local variable injection).
 *
 * Handles:
 *   - {% include 'partials/header' %}
 *   - {% include 'partials/button' with text = "S'inscrire", color = "blue" %}
 *
 * Fully recursive: supports arbitrary nesting of plain and parameterised includes,
 * with scope isolation via unique backup variable names and cycle detection via
 * TemplateInclusionStack.
 */
final class IncludeParser implements ParserInterface
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
            '/\{%\s*include\s+[\'"](.+?)[\'"](?:\s+with\s+(.+?))?\s*%\}/s',
            function ($matches) {
                $templateName = $matches[1];
                $rawVariables = $matches[2] ?? null;
                $templatePath = $this->resolver->resolveTemplatePath($templateName);

                if (!file_exists($templatePath)) {
                    throw new \RuntimeException("Included template '$templateName' not found: $templatePath");
                }

                $this->stack->push($templatePath);

                try {
                    $rawContent     = file_get_contents($templatePath);
                    $includeContent = $this->syntaxValidator->parse($rawContent);

                    // Recursive resolution of inner includes (both plain and with-variables)
                    $includeContent = $this->parse($includeContent);

                    if ($rawVariables === null || trim($rawVariables) === '') {
                        return $includeContent;
                    }

                    // Unique backup variable name per inclusion to prevent parent-child collision in PHP scope
                    $backupVar = '$__zaraBackup_' . bin2hex(random_bytes(4));
                    $segments  = $this->variables->splitTopLevelAssignments($rawVariables);

                    $phpBefore = "<?php {$backupVar} = []; ";
                    $phpAssign = "";

                    foreach ($segments as $segment) {
                        [$varName, $rawValue] = array_map('trim', explode('=', $segment, 2));
                        $value = $this->variables->resolve($rawValue);

                        $phpBefore .= "if (isset(\${$varName})) { {$backupVar}['{$varName}'] = \${$varName}; } ";
                        $phpAssign .= "\${$varName} = {$value}; ";
                    }
                    $phpBefore .= $phpAssign . "?>";

                    $phpAfter = "<?php foreach ({$backupVar} as \$__k => \$__v) { \$\$__k = \$__v; } ?>";

                    return $phpBefore . $includeContent . $phpAfter;
                } finally {
                    $this->stack->pop();
                }
            },
            $content
        );
    }
}