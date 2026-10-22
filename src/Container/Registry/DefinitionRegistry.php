<?php
declare(strict_types=1);

namespace Zara\Container\Registry;

use Zara\Container\Exceptions\ContainerException;

final class DefinitionRegistry
{
    /** @var array<string, array> */
    private array $definitions = [];
    private array $defaults = [];

    public function define(string $id, array $definition): void
    {
        $this->definitions[$id] = $definition;
    }

    public function getDefinition(string $id): array
    {
        if (!$this->hasDefinition($id)) {
            throw new ContainerException("Container Error: Service definition for '$id' not found.");
        }
        return array_merge($this->defaults, $this->definitions[$id]);
    }

    public function hasDefinition(string $id): bool
    {
        return isset($this->definitions[$id]);
    }

    public function setDefaults(array $defaults): void
    {
        $this->defaults = $defaults;
    }

    public function getDefaults(): array
    {
        return $this->defaults;
    }

    /** @return list<string> */
    public function allIds(): array
    {
        return array_keys($this->definitions);
    }
}