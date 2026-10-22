<?php
declare(strict_types=1);

namespace Zara\Container\Compiler;

/**
 * Result of the compilability analysis: separates services that
 * ServiceCodeGenerator can translate into direct PHP code from those that
 * must remain dynamically resolved by ServiceFactory at runtime.
 */
final class CompilationPlan
{
    /** @var array<string, array> id => definition */
    private array $compilable = [];

    /** @var array<string, string> id => raison de l'exclusion */
    private array $excluded = [];

    public function addCompilable(string $id, array $definition): void
    {
        $this->compilable[$id] = $definition;
    }

    public function addExcluded(string $id, string $reason): void
    {
        $this->excluded[$id] = $reason;
    }

    /**
     * Removes $id from compilable() and adds it to excluded(). Used when
     * dependency graph analysis (after the first pass of
     * ContainerCompiler::plan()) reveals that a service initially considered
     * compilable is not (because of a non-compilable dependency or a cycle
     * among compiled services).
     */
    public function demote(string $id, string $reason): void
    {
        unset($this->compilable[$id]);
        $this->excluded[$id] = $reason;
    }

    /** @return array<string, array> */
    public function compilable(): array
    {
        return $this->compilable;
    }

    /** @return array<string, string> */
    public function excluded(): array
    {
        return $this->excluded;
    }
}
