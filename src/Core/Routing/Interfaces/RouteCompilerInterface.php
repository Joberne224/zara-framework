<?php

declare(strict_types=1);

namespace Zara\Core\Routing\Interfaces;

/**
 * Contract for compiling route paths into regular expressions and generating URLs.
 */
interface RouteCompilerInterface
{
    public function constrain(string $param, string $pattern): static;

    /**
     * @param array<string, string> $constraints
     */
    public function constrainMany(array $constraints): static;

    /**
     * @return array<string, string>|null
     */
    public function match(string $url): ?array;

    /**
     * @param array<string, scalar> $params
     */
    public function generate(array $params): string;

    /**
     * @return list<string>
     */
    public function getParameterNames(): array;

    public function getOriginalPath(): string;

    public function getCompiledPath(): string;

    /**
     * @return array<string, string>
     */
    public function getConstraints(): array;
}