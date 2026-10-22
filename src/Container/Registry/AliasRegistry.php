<?php
declare(strict_types=1);

namespace Zara\Container\Registry;

use Zara\Container\Exceptions\ContainerException;

final class AliasRegistry
{
    /** @var array<string, string> */
    private array $aliases = [];

    public function set(string $aliasId, string $targetId): void
    {
        if ($aliasId === '' || $targetId === '') {
            throw new ContainerException("Container Error: Alias and target IDs must not be empty.");
        }
        if ($aliasId === $targetId || $this->resolve($targetId) === $aliasId) {
            throw new ContainerException("Container Error: Circular alias detected for '$aliasId'.");
        }
        if (isset($this->aliases[$aliasId])) {
            throw new ContainerException("Container Error: Alias '$aliasId' is already defined.");
        }
        $this->aliases[$aliasId] = $targetId;
    }

    public function has(string $aliasId): bool
    {
        return isset($this->aliases[$aliasId]);
    }

    /**
     * Follows the alias chain to the real ID. An alias may point to another
     * alias; detect cycles instead of recursing indefinitely.
     */
    public function resolve(string $id): string
    {
        $seen = [];
        while (isset($this->aliases[$id])) {
            if (isset($seen[$id])) {
                throw new ContainerException(
                    "Container Error: Circular alias detected: " . implode(' -> ', array_keys($seen)) . " -> $id"
                );
            }
            $seen[$id] = true;
            $id = $this->aliases[$id];
        }

        return $id;
    }

    /** @param callable(string): bool $targetExists */
    public function validateTargets(callable $targetExists): void
    {
        foreach (array_keys($this->aliases) as $aliasId) {
            $targetId = $this->resolve($aliasId);
            if (!$targetExists($targetId)) {
                throw new ContainerException("Container Error: Alias target '$targetId' for '$aliasId' does not exist.");
            }
        }
    }
}
