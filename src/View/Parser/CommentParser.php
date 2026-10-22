<?php
declare(strict_types=1);

namespace Zara\View\Parser;

final class CommentParser implements ParserInterface
{
    /**
     * {@inheritDoc}
     */
    public function parse(string $content): string
    {
        // 1. Remove multiline comments that occupy entire lines.
        // Consume adjacent whitespace and the trailing newline as well.
        $content = preg_replace('/^\s*\/\*.*?\*\/\s*(?:\r?\n|$)/ms', '', $content);

        // 2. Remove inline multiline comments.
        $content = preg_replace('/\/\*.*?\*\//s', '', $content);

        // 3. Remove single-line comments (// and #) that start a line.
        // Consume the whole line and trailing newline to avoid blank lines.
        $content = preg_replace('/^\s*(?:\/\/|#).*(?:\r?\n|$)/m', '', $content);

        return $content;
    }
}
