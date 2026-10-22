<?php
declare(strict_types=1);

namespace Zara\View\Cache\Internal;

/**
 * Statically inspects compiled template source for constructs the
 * cache refuses to persist: shell execution, superglobal access,
 * dynamic variable-variables, and forbidden function calls.
 *
 * This is the single source of truth for superglobal blocking. A
 * duplicate, string-prefix-based check used to live in VariableParser,
 * but it only matched an explicit '$_GET'-style expression — never the
 * bare 'user.name'-style syntax this DSL actually uses everywhere else
 * — and it failed silently (empty output) instead of raising an error.
 * Worse, its list of names had drifted from this one (it protected
 * _SESSION but not GLOBALS; this one protected GLOBALS but not
 * _SESSION). Checking PHP tokens on the final compiled output, as done
 * here, catches superglobal access regardless of which parser produced
 * it and regardless of template-level spelling, so it's the only check
 * that needs to exist.
 */
final class CompiledCodeInspector
{
    private const FORBIDDEN_FUNCTIONS = ['system', 'exec', 'shell_exec', 'eval', 'create_function', 'passthru'];

    private const FORBIDDEN_SUPERGLOBALS = [
        '$GLOBALS', '$_GET', '$_POST', '$_REQUEST', '$_SERVER',
        '$_COOKIE', '$_SESSION', '$_ENV', '$_FILES',
    ];

    /**
     * @return array{safe: bool, reason: string}
     */
    public function inspect(string $code): array
    {
        $tokens = token_get_all('<?php ' . $code);
        $tokenCount = count($tokens);

        for ($i = 0; $i < $tokenCount; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                if ($token === '`') {
                    $nearbyLine = $this->findNearbyLine($tokens, $i, $tokenCount);
                    return [
                        'safe' => false,
                        'reason' => "Execution backtick operator (`) detected around line {$nearbyLine}."
                    ];
                }

                // Inspect dynamic-variable syntax token by token.
                if ($token === '$') {
                    $nextToken = $tokens[$i + 1] ?? null;
                    $nearbyLine = is_array($nextToken) ? $nextToken[2] : $this->findNearbyLine($tokens, $i, $tokenCount);

                    // Internal framework variables such as $__currentLoopData are allowed.
                    if (is_array($nextToken) && $nextToken[0] === T_VARIABLE) {
                        $varName = $nextToken[1];
                        if (str_starts_with($varName, '$__')) {
                            continue; // Allow the engine's internal variable references.
                        }
                    }

                    return [
                        'safe' => false,
                        'reason' => "Unauthorized dynamic variable-variable syntax ($$) detected around line {$nearbyLine}."
                    ];
                }
                continue;
            }

            [$id, $text, $line] = $token;

            if ($id === T_DOLLAR_OPEN_CURLY_BRACES) {
                return [
                    'safe' => false,
                    'reason' => "Complex dynamic variable interpolation (\${...}) detected on line {$line}."
                ];
            }

            if ($id === T_VARIABLE) {
                if (in_array($text, self::FORBIDDEN_SUPERGLOBALS, true)) {
                    return [
                        'safe' => false,
                        'reason' => "Direct access to superglobal variable '{$text}' is prohibited inside templates (line {$line})."
                    ];
                }
                continue;
            }

            if ($id !== T_STRING) {
                continue;
            }

            if (!in_array(strtolower($text), self::FORBIDDEN_FUNCTIONS, true)) {
                continue;
            }

            $prev = $this->prevMeaningfulToken($tokens, $i - 1);
            if ($prev !== null && in_array($prev, ['->', '?->', '::', '\\'], true)) {
                continue;
            }

            $next = $this->nextMeaningfulToken($tokens, $i + 1);
            if ($next !== null && $next === '(') {
                return [
                    'safe' => false,
                    'reason' => "Unsafe global function call '{$text}()' detected on line {$line}."
                ];
            }
        }

        return ['safe' => true, 'reason' => ''];
    }

    private function findNearbyLine(array $tokens, int $currentIndex, int $tokenCount): string
    {
        for ($j = $currentIndex + 1; $j < min($currentIndex + 5, $tokenCount); $j++) {
            if (is_array($tokens[$j])) {
                return (string) $tokens[$j][2];
            }
        }
        return 'unknown';
    }

    private function nextMeaningfulToken(array $tokens, int $startIndex): ?string
    {
        for ($i = $startIndex; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            if (is_array($token)) {
                if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                return $token[1];
            }
            return $token;
        }
        return null;
    }

    private function prevMeaningfulToken(array $tokens, int $startIndex): ?string
    {
        for ($i = $startIndex; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (is_array($token)) {
                if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                return $token[1];
            }
            return $token;
        }
        return null;
    }
}
