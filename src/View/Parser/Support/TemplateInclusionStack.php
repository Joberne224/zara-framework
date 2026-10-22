<?php

declare(strict_types=1);

namespace Zara\View\Parser\Support;

use Zara\View\Exceptions\CircularIncludeException;

/**
 * Tracks the chain of template files currently being resolved (via
 * {% include %} or {% extends %}) so a circular reference can be
 * detected instead of recursing until PHP's stack limit is hit.
 *
 * Shared as a single instance between BlockParser, IncludeParser and
 * IncludeWithVariablesParser — same pattern as TemplateSyntaxValidator.
 */
final class TemplateInclusionStack
{
    /** @var string[] */
    private array $paths = [];

    /**
     * @throws CircularIncludeException if $path is already being resolved
     *         somewhere up the current chain
     */
    public function push(string $path, int $templateLine = 0): void
    {
        if (in_array($path, $this->paths, true)) {
            throw CircularIncludeException::forChain([...$this->paths, $path], $templateLine);
        }

        $this->paths[] = $path;
    }

    public function pop(): void
    {
        array_pop($this->paths);
    }

    /** @return string[] */
    public function currentChain(): array
    {
        return $this->paths;
    }
}