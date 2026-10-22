<?php
declare(strict_types=1);

namespace Zara\View\Parser;

use Zara\View\Exceptions\TemplateSyntaxException;

/**
 * Detects malformed template tags before compilation, including unclosed
 * openers and orphaned closers that suggest a mistyped opener.
 *
 * This parser runs first so reported line numbers refer to the original
 * source. It never modifies the content; it returns it unchanged or throws
 * TemplateSyntaxException.
 *
 * Orphaned "%}" and "!!}" sequences are always reported because they are
 * unlikely to occur naturally in HTML, CSS, or JavaScript. Orphaned "}}"
 * sequences can occur in minified JSON or nested JavaScript objects, so they
 * are reported only when preceded nearby by a brace that resembles a tag
 * opener, and outside <script>/<style> blocks.
 *
 * Client-side template syntax using `{{ }}` outside script/style blocks can
 * still collide with this syntax. An explicit escape syntax may be added if
 * that use case is needed.
 */
final class TemplateSyntaxValidator implements ParserInterface
{
    /**
     * @var array<int, array{open: string, close: string, label: string}>
     */
    private const DELIMITERS = [
        ['open' => '{!!', 'close' => '!!}', 'label' => 'raw output {!! !!}'],
        ['open' => '{{',  'close' => '}}',  'label' => 'expression {{ }}'],
        ['open' => '{%',  'close' => '%}',  'label' => 'directive {% %}'],
    ];

    /** Maximum character distance for associating an orphaned "}}" with a nearby unmatched brace. */
    private const NEARBY_BRACE_WINDOW = 40;

    public function parse(string $content): string
    {
        $length = strlen($content);
        $i = 0;
        $lastBrace = null;
        $htmlMode = 'text';
        $htmlQuote = null;
        $tagBuffer = '';
        $rawElement = null;

        while ($i < $length) {
            $opener = $this->matchOpenerAt($content, $i);

            if ($opener !== null) {
                $this->assertSafeTemplateContext(
                    $content,
                    $i,
                    $htmlMode,
                    $htmlQuote,
                    $tagBuffer,
                    $rawElement
                );

                $closePos = $this->findTagClose(
                    $content,
                    $i + strlen($opener['open']),
                    $opener['close']
                );

                if ($closePos === false) {
                    throw $this->syntaxError(
                        $content,
                        $i,
                        "Unclosed {$opener['label']} tag: '{$opener['open']}' has no matching '{$opener['close']}' later in the file."
                    );
                }

                $i = $closePos + strlen($opener['close']);
                $lastBrace = null;
                continue;
            }

            $closer = $this->matchOrphanCloserAt($content, $i);
            if ($closer !== null && $htmlMode === 'text' && $rawElement === null) {
                [$sequence, $label, $alwaysFlag] = $closer;

                $hasNearbyBrace = $lastBrace !== null
                    && ($i - $lastBrace) <= self::NEARBY_BRACE_WINDOW
                    && preg_match('/[\r\n]/', substr($content, $lastBrace, $i - $lastBrace)) !== 1;

                if ($alwaysFlag || $hasNearbyBrace) {
                    $errorPos = $hasNearbyBrace ? $lastBrace : $i;
                    throw $this->syntaxError(
                        $content,
                        $errorPos,
                        "Malformed {$label} tag: '{$sequence}' has no matching opener " .
                        "(a brace may be missing, or an invalid character may have replaced '{', '%' or '!')."
                    );
                }

                $i += strlen($sequence);
                continue;
            }

            if ($content[$i] === '{') {
                $lastBrace = $i;
            }

            $i += $this->advanceHtmlContext(
                $content,
                $i,
                $htmlMode,
                $htmlQuote,
                $tagBuffer,
                $rawElement
            );
            if ($htmlMode !== 'text' || $rawElement !== null) {
                $lastBrace = null;
            }
        }

        return $content;
    }

    private function assertSafeTemplateContext(
        string $content,
        int $position,
        string $htmlMode,
        ?string $htmlQuote,
        string $tagBuffer,
        ?string $rawElement
    ): void
    {
        if ($rawElement !== null) {
            throw $this->syntaxError($content, $position, 'Template tags are not allowed inside script or style elements.');
        }

        if ($htmlMode === 'comment') {
            throw $this->syntaxError($content, $position, 'Template tags are not allowed inside HTML comments.');
        }

        if ($htmlMode !== 'tag') {
            return;
        }

        if (preg_match('/\bon[a-z0-9_-]+\s*=|\bstyle\s*=/i', $tagBuffer)) {
            throw $this->syntaxError($content, $position, 'Dynamic output is not allowed in event-handler or style attributes.');
        }

        if ($htmlQuote === null) {
            throw $this->syntaxError(
                $content,
                $position,
                'Dynamic output inside an HTML tag must be placed in a quoted attribute value.'
            );
        }
    }

    private function findTagClose(string $content, int $start, string $closingDelimiter): int|false
    {
        $length = strlen($content);
        $quote = null;

        for ($i = $start; $i < $length; $i++) {
            $char = $content[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if (substr($content, $i, 2) === '/*'
                || substr($content, $i, 2) === '//'
                || $char === '#') {
                throw $this->syntaxError($content, $i, 'Comments are not allowed inside template tags.');
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }

            if (substr($content, $i, strlen($closingDelimiter)) === $closingDelimiter) {
                return $i;
            }
        }

        return false;
    }

    /**
     * Advances the lightweight HTML context tracker by one or more bytes.
     *
     * This is intentionally a single pass: each byte advances the HTML mode
     * without rescanning a huge prefix for every template expression.
     */
    private function advanceHtmlContext(
        string $content,
        int $position,
        string &$mode,
        ?string &$quote,
        string &$tagBuffer,
        ?string &$rawElement
    ): int {
        if ($mode === 'comment') {
            if (substr($content, $position, 3) === '-->') {
                $mode = 'text';
                return 3;
            }
            return 1;
        }

        if ($mode === 'text') {
            if (substr($content, $position, 4) === '<!--') {
                $mode = 'comment';
                return 4;
            }

            if ($content[$position] === '<' && $this->startsHtmlTag($content, $position)) {
                $mode = 'tag';
                $quote = null;
                $tagBuffer = '';
                return 1;
            }

            return 1;
        }

        if ($mode !== 'tag') {
            return 1;
        }

        $char = $content[$position];
        if ($quote !== null) {
            if ($char === $quote) {
                $quote = null;
            }
            $tagBuffer .= $char;
            return 1;
        }

        if ($char === '"' || $char === "'") {
            $quote = $char;
            $tagBuffer .= $char;
            return 1;
        }

        if ($char !== '>') {
            $tagBuffer .= $char;
            return 1;
        }

        if (preg_match('/^\\s*\\/?\\s*([A-Za-z][A-Za-z0-9:-]*)\\b/', $tagBuffer, $match)) {
            $tagName = strtolower($match[1]);
            $isClosing = preg_match('/^\\s*\\//', $tagBuffer) === 1;
            if (!$isClosing && in_array($tagName, ['script', 'style'], true)) {
                $rawElement = $tagName;
            } elseif ($isClosing && $tagName === $rawElement) {
                $rawElement = null;
            }
        }

        $tagBuffer = '';
        $mode = 'text';
        return 1;
    }

    private function startsHtmlTag(string $content, int $position): bool
    {
        $tail = substr($content, $position, 16);

        return preg_match('/^<(?:\\/?[A-Za-z]|!--|\\?)/', $tail) === 1;
    }

    /**
     * @return array{open: string, close: string, label: string}|null
     */
    private function matchOpenerAt(string $content, int $pos): ?array
    {
        foreach (self::DELIMITERS as $delimiter) {
            if (substr($content, $pos, strlen($delimiter['open'])) === $delimiter['open']) {
                return $delimiter;
            }
        }
        return null;
    }

    /**
     * @return array{0: string, 1: string, 2: bool}|null [sequence, label, always report]
     */
    private function matchOrphanCloserAt(string $content, int $pos): ?array
    {
        if (substr($content, $pos, 3) === '!!}') {
            return ['!!}', 'raw output {!! !!}', true];
        }
        if (substr($content, $pos, 2) === '%}') {
            return ['%}', 'directive {% %}', true];
        }
        if (substr($content, $pos, 2) === '}}') {
            return ['}}', 'expression {{ }}', false];
        }
        return null;
    }

    private function syntaxError(string $content, int $position, string $message): TemplateSyntaxException
    {
        preg_match_all('/\r\n|\r|\n/', substr($content, 0, $position), $lineBreaks);
        $line    = count($lineBreaks[0]) + 1;
        $start   = max(0, $position - 20);
        $length  = min(50, strlen($content) - $start);
        $snippet = trim((string) preg_replace('/\s+/', ' ', substr($content, $start, $length)));

        return new TemplateSyntaxException(
            "{$message} Context: '{$snippet}'",
            $line
        );
    }
}
