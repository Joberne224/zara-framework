<?php

declare(strict_types=1);

namespace Zara\View\Parser\Support;

use Zara\View\Runtime\TypeChecker;
use RuntimeException;

final class VariableExpressionResolver
{
    private const RESERVED_WORDS = ['and', 'or', 'true', 'false', 'null'];

    private const MAGIC_CONSTANTS = [
        '__LINE__', '__FILE__', '__DIR__', '__FUNCTION__',
        '__CLASS__', '__TRAIT__', '__METHOD__', '__NAMESPACE__',
    ];

    public function guardAgainstMagicConstant(string $identifier): void
    {
        if (in_array(strtoupper($identifier), self::MAGIC_CONSTANTS, true)) {
            throw new RuntimeException(
                "Use of the PHP magic constant '{$identifier}' is not allowed in template expressions."
            );
        }
    }

    public function resolve(string $expression): string
    {
        $length = strlen($expression);
        $output = '';
        $i = 0;

        while ($i < $length) {
            $char = $expression[$i];

            if (substr($expression, $i, 2) === '->' || substr($expression, $i, 3) === '?->') {
                throw new RuntimeException(
                    "Direct PHP object access is not allowed in template expressions; use dot access instead."
                );
            }

            if ($char === '"' || $char === "'") {
                [$literal, $i] = $this->consumeStringLiteral($expression, $i);
                $output .= $literal;
                continue;
            }

            if ($char === '$') {
                $start = $i;
                $i++;
                while ($i < $length && $this->isIdentifierChar($expression[$i])) {
                    $i++;
                }
                if ($this->isFollowedByCall($expression, $i)) {
                    throw new RuntimeException('Calling a template variable is not allowed.');
                }
                $output .= substr($expression, $start, $i - $start);
                continue;
            }

            if (substr($expression, $i, 2) === '×') {
                $output .= '*';
                $i += 2;
                continue;
            }
            if (substr($expression, $i, 2) === '÷') {
                $output .= '/';
                $i += 2;
                continue;
            }

            if ($this->isIdentifierStart($char)) {
                $start = $i;
                $i++;
                while ($i < $length && $this->isIdentifierChar($expression[$i])) {
                    $i++;
                }
                $identifier = substr($expression, $start, $i - $start);

                if ($this->isPrecededByObjectArrow($expression, $start)) {
                    throw new RuntimeException(
                        "Direct PHP object access is not allowed in template expressions; use dot access instead."
                    );
                }

                $this->guardAgainstMagicConstant($identifier);

                if ($this->startsDotChain($expression, $i)) {
                    [$chainOutput, $i] = $this->consumeDotChain($expression, $i, '$' . $identifier);
                    [$chainOutput, $i] = $this->tryConsumeTestSuffix($expression, $i, $chainOutput, $identifier);
                    $output .= $chainOutput;
                    continue;
                }

                if (in_array(strtolower($identifier), self::RESERVED_WORDS, true)) {
                    [$reservedOutput, $i] = $this->tryConsumeTestSuffix($expression, $i, $identifier, $identifier);
                    $output .= $reservedOutput;
                    continue;
                }

                if ($this->isFollowedByCall($expression, $i)) {
                    throw new RuntimeException(
                        "Function calls are only allowed through registered template functions."
                    );
                }

                $bareOutput = '$' . $identifier;
                [$bareOutput, $i] = $this->tryConsumeTestSuffix($expression, $i, $bareOutput, $identifier);
                $output .= $bareOutput;
                continue;
            }

            if ($char === ')' && $this->isFollowedByCall($expression, $i + 1)) {
                throw new RuntimeException('Indirect function calls are not allowed in template expressions.');
            }

            $previous = $i - 1;
            while ($previous >= 0 && ctype_space($expression[$previous])) {
                $previous--;
            }
            if ($char === '(' && $previous >= 0 && $expression[$previous] === ']') {
                throw new RuntimeException('Calling an indexed template value is not allowed.');
            }

            $output .= $char;
            $i++;
        }

        return $output;
    }

    private function tryConsumeTestSuffix(string $expression, int $i, string $resolvedExpr, string $rootIdentifier): array
    {
        $pos = $this->skipWhitespace($expression, $i);

        if (!$this->matchesKeyword($expression, $pos, 'is')) {
            return [$resolvedExpr, $i];
        }

        // Once the 'is' keyword is recognized, an unsupported suffix is an error.
        $pos = $this->skipWhitespace($expression, $pos + 2);

        $negate = false;
        if ($this->matchesKeyword($expression, $pos, 'not')) {
            $negate = true;
            $pos = $this->skipWhitespace($expression, $pos + 3);
        }

        // 1. Test "defined"
        if ($this->matchesKeyword($expression, $pos, 'defined')) {
            $pos += strlen('defined');
            $check = $this->buildDefinedCheck($resolvedExpr, $rootIdentifier);
            return [$negate ? "!({$check})" : $check, $pos];
        }

        // 2. Type and state tests (resource, object, iterable, empty, null, etc.).
        foreach (TypeChecker::getSupportedTests() as $test) {
            if ($this->matchesKeyword($expression, $pos, $test)) {
                $pos += strlen($test);
                $check = $this->buildTypeCheck($resolvedExpr, $rootIdentifier, $test);
                return [$negate ? "!({$check})" : $check, $pos];
            }
        }

        $allowedTests = implode("', '", array_merge(['defined'], TypeChecker::getSupportedTests()));
        throw new RuntimeException(
            "Invalid template syntax: 'is' keyword must be followed by one of ['{$allowedTests}'] (or preceded by 'not') in expression: '{$expression}'."
        );
    }

    private function buildDefinedCheck(string $resolvedExpr, string $rootIdentifier): string
    {
        if (in_array(strtolower($rootIdentifier), self::RESERVED_WORDS, true)) {
            return strtolower($rootIdentifier) === 'null' ? 'false' : 'true';
        }

        $bareVariable = '$' . $rootIdentifier;

        if ($resolvedExpr === $bareVariable) {
            return "isset({$bareVariable})";
        }

        return sprintf(
            'isset(%s) && \Zara\View\Runtime\DefinedGuard::check(static function () use (%s, $__zaraCallGuard) { return %s; })',
            $bareVariable,
            $bareVariable,
            $resolvedExpr
        );
    }

    private function buildTypeCheck(string $resolvedExpr, string $rootIdentifier, string $test): string
    {
        $bareVariable = '$' . $rootIdentifier;

        return sprintf(
            'isset(%s) && \Zara\View\Runtime\TypeChecker::check(static function () use (%s, $__zaraCallGuard) { return %s; }, %s)',
            $bareVariable,
            $bareVariable,
            $resolvedExpr,
            var_export($test, true)
        );
    }

    /**
     * Returns true if a chain segment (`.identifier` or `?.identifier`)
     * starts at position $i. Shared by resolve() and consumeDotChain().
     */
    private function startsDotChain(string $expression, int $i): bool
    {
        $length = strlen($expression);

        if ($i < $length && $expression[$i] === '.'
            && $i + 1 < $length && $this->isIdentifierStart($expression[$i + 1])
        ) {
            return true;
        }

        return $i + 1 < $length && $expression[$i] === '?' && $expression[$i + 1] === '.'
            && $i + 2 < $length && $this->isIdentifierStart($expression[$i + 2]);
    }

    private function consumeDotChain(string $expression, int $i, string $baseOutput): array
    {
        $length = strlen($expression);
        $chainOutput = $baseOutput;

        while ($this->startsDotChain($expression, $i)) {
            $isNullsafe = $expression[$i] === '?';
            $i += $isNullsafe ? 2 : 1;

            $segmentStart = $i;
            $i++;
            while ($i < $length && $this->isIdentifierChar($expression[$i])) {
                $i++;
            }
            $segment = substr($expression, $segmentStart, $i - $segmentStart);

            $this->guardAgainstMagicConstant($segment);

            $callPos = $this->skipWhitespace($expression, $i);

            if ($callPos < $length && $expression[$callPos] === '(') {
                [$rawArguments, $i] = $this->consumeCallArguments($expression, $callPos);
                $resolvedArguments = array_map(
                    fn (string $argument): string => $this->resolve($argument),
                    $rawArguments
                );
                $method = $isNullsafe ? 'callOrNull' : 'call';
                $chainOutput = sprintf(
                    '\Zara\View\Runtime\AttributeAccessor::%s(%s, %s, [%s], $__zaraCallGuard)',
                    $method,
                    $chainOutput,
                    var_export($segment, true),
                    implode(', ', $resolvedArguments)
                );
                continue;
            }

            $method = $isNullsafe ? 'getOrNull' : 'get';
            $chainOutput = sprintf(
                '\Zara\View\Runtime\AttributeAccessor::%s(%s, %s, $__zaraCallGuard)',
                $method,
                $chainOutput,
                var_export($segment, true)
            );
        }

        return [$chainOutput, $i];
    }

    private function skipWhitespace(string $expression, int $pos): int
    {
        $length = strlen($expression);
        while ($pos < $length && ($expression[$pos] === ' ' || $expression[$pos] === "\t")) {
            $pos++;
        }
        return $pos;
    }

    private function matchesKeyword(string $expression, int $pos, string $keyword): bool
    {
        $length = strlen($expression);
        $keywordLength = strlen($keyword);

        if (substr($expression, $pos, $keywordLength) !== $keyword) {
            return false;
        }

        $next = $pos + $keywordLength;
        return $next >= $length || !$this->isIdentifierChar($expression[$next]);
    }

    private function consumeCallArguments(string $expression, int $openParenPos): array
    {
        $length = strlen($expression);
        $i = $openParenPos + 1;
        $argumentStart = $i;
        $depth = 0;
        $arguments = [];

        while ($i < $length) {
            $char = $expression[$i];

            if ($char === '"' || $char === "'") {
                [, $i] = $this->consumeStringLiteral($expression, $i);
                continue;
            }

            if ($char === '(' || $char === '[') {
                $depth++;
                $i++;
                continue;
            }

            if ($char === ')' && $depth === 0) {
                $tail = trim(substr($expression, $argumentStart, $i - $argumentStart));
                if ($tail !== '') {
                    $arguments[] = $tail;
                }
                $i++;
                break;
            }

            if ($char === ')' || $char === ']') {
                $depth--;
                $i++;
                continue;
            }

            if ($char === ',' && $depth === 0) {
                $segment = trim(substr($expression, $argumentStart, $i - $argumentStart));
                if ($segment === '') {
                    throw new RuntimeException(
                        "Empty argument in call at position {$i} of expression: {$expression}"
                    );
                }
                $arguments[] = $segment;
                $i++;
                $argumentStart = $i;
                continue;
            }

            $i++;
        }

        return [$arguments, $i];
    }

    public function splitTopLevelAssignments(string $expression): array
    {
        $length = strlen($expression);
        $i = 0;
        $segmentStart = 0;
        $depth = 0;
        $segments = [];

        while ($i < $length) {
            $char = $expression[$i];

            if ($char === '"' || $char === "'") {
                [, $i] = $this->consumeStringLiteral($expression, $i);
                continue;
            }

            if ($char === '(' || $char === '[') {
                $depth++;
                $i++;
                continue;
            }

            if ($char === ')' || $char === ']') {
                $depth--;
                $i++;
                continue;
            }

            if ($char === ',' && $depth === 0) {
                $segment = trim(substr($expression, $segmentStart, $i - $segmentStart));
                if ($segment === '') {
                    throw new RuntimeException(
                        "Empty assignment segment at position {$i} of: {$expression}"
                    );
                }
                $segments[] = $segment;
                $i++;
                $segmentStart = $i;
                continue;
            }

            $i++;
        }

        $tail = trim(substr($expression, $segmentStart));
        if ($tail !== '') {
            $segments[] = $tail;
        }

        return $segments;
    }

    private function consumeStringLiteral(string $expression, int $start): array
    {
        $length = strlen($expression);
        $quote  = $expression[$start];
        $i = $start + 1;

        while ($i < $length) {
            if ($expression[$i] === '\\' && $i + 1 < $length) {
                $i += 2;
                continue;
            }
            if ($expression[$i] === $quote) {
                $i++;
                break;
            }
            $i++;
        }

        return [substr($expression, $start, $i - $start), $i];
    }

    private function isIdentifierStart(string $char): bool
    {
        return $char === '_'
            || ($char >= 'a' && $char <= 'z')
            || ($char >= 'A' && $char <= 'Z')
            || ord($char) >= 0x7f;
    }

    private function isIdentifierChar(string $char): bool
    {
        return $this->isIdentifierStart($char) || ($char >= '0' && $char <= '9');
    }

    private function isFollowedByCall(string $expression, int $pos): bool
    {
        $length = strlen($expression);
        while ($pos < $length) {
            if (ctype_space($expression[$pos])) {
                $pos++;
                continue;
            }
            if (substr($expression, $pos, 2) === '/*') {
                $end = strpos($expression, '*/', $pos + 2);
                if ($end === false) {
                    return false;
                }
                $pos = $end + 2;
                continue;
            }
            if (substr($expression, $pos, 2) === '//'
                || $expression[$pos] === '#') {
                $end = strcspn($expression, "\r\n", $pos);
                $pos += $end;
                continue;
            }
            break;
        }
        return $pos < $length && $expression[$pos] === '(';
    }

    private function isPrecededByObjectArrow(string $expression, int $pos): bool
    {
        while ($pos > 0 && ($expression[$pos - 1] === ' ' || $expression[$pos - 1] === "\t")) {
            $pos--;
        }
        return $pos >= 2 && $expression[$pos - 1] === '>' && $expression[$pos - 2] === '-';
    }
}
