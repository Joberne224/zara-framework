<?php

declare(strict_types=1);

namespace Zara\Core\Routing;

use Zara\Core\Routing\Compiler\RoutePathValidator;
use Zara\Core\Routing\Compiler\RoutePatternAssembler;
use Zara\Core\Routing\Compiler\RouteUrlGenerator;
use Zara\Core\Routing\Interfaces\RouteCompilerInterface;

/**
 * Compiles a route path into a named regular expression.
 *
 * @package Zara\Core\Routing
 */
final class RouteCompiler implements RouteCompilerInterface
{
    private string $compiledPath;

    /** @var array<string, string> */
    private array $constraints = [];

    private ?string $matchPattern = null;

    public function __construct(
        private readonly string $originalPath,
        private readonly RoutePathValidator $validator = new RoutePathValidator(),
        private readonly RoutePatternAssembler $assembler = new RoutePatternAssembler(),
        private readonly RouteUrlGenerator $urlGenerator = new RouteUrlGenerator(),
    ) {
        $this->compiledPath = $originalPath;

        $this->validator->assertAtMostOneWildcard($this->compiledPath, $this->originalPath);
        $this->validator->assertOptionalIsTrailing($this->compiledPath, $this->originalPath);
    }

    public function constrain(string $param, string $pattern): static
    {
        $placeholderRegex = $this->assembler->placeholderRegexFor($param);

        $this->validator->assertParamNameValid($param);
        $this->validator->assertParamExists($param, $placeholderRegex, $this->compiledPath, $this->originalPath);
        $this->validator->assertPatternValid($param, $pattern);

        $this->constraints[$param] = $pattern;

        $result = preg_replace_callback(
            $placeholderRegex,
            fn() => $this->assembler->namedGroup($param, $pattern),
            $this->compiledPath
        );

        if ($result !== null) {
            $this->compiledPath = $result;
            $this->matchPattern = null;
        }

        return $this;
    }

    public function constrainMany(array $constraints): static
    {
        foreach ($constraints as $param => $pattern) {
            $this->constrain($param, $pattern);
        }

        return $this;
    }

    public function match(string $url): ?array
    {
        $pattern = $this->matchPattern ??= $this->assembler->buildMatchPattern($this->compiledPath);

        if (!preg_match($pattern, $url, $matches)) {
            return null;
        }

        return array_filter(
            $matches,
            fn($key) => !is_int($key),
            ARRAY_FILTER_USE_KEY
        );
    }

    public function generate(array $params): string
    {
        return $this->urlGenerator->generate($this->originalPath, $params);
    }

    public function getParameterNames(): array
    {
        return $this->assembler->extractParameterNames($this->originalPath);
    }

    public function getOriginalPath(): string
    {
        return $this->originalPath;
    }

    public function getCompiledPath(): string
    {
        return $this->compiledPath;
    }

    public function getConstraints(): array
    {
        return $this->constraints;
    }
}