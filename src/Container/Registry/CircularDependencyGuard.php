<?php
declare(strict_types=1);

namespace Zara\Container\Registry;

use Zara\Container\Exceptions\ContainerException;

final class CircularDependencyGuard
{
    /** @var array<string, bool> */
    private array $resolving = [];

    public function mark(string $id): void
    {
        if (isset($this->resolving[$id])) {
            throw new ContainerException(
                "Container Error: Circular dependency detected while resolving service '$id'. " .
                "Path: " . implode(' -> ', array_keys($this->resolving)) . " -> $id"
            );
        }
        $this->resolving[$id] = true;
    }

    public function unmark(string $id): void
    {
        unset($this->resolving[$id]);
    }

    public function isResolving(string $id): bool
    {
        return isset($this->resolving[$id]);
    }

    /**
     * @return list<string> The current resolution chain, in order.
     */
    public function stack(): array
    {
        return array_keys($this->resolving);
    }
}
