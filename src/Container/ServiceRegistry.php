<?php
declare(strict_types=1);

namespace Zara\Container;

use Zara\Container\Exceptions\ContainerException;
use Zara\Container\Registry\AliasRegistry;
use Zara\Container\Registry\CircularDependencyGuard;
use Zara\Container\Registry\DefinitionRegistry;
use Zara\Container\Registry\InstanceStorage;
use Zara\Container\Registry\TagIndex;
use Zara\Container\Registry\ResolutionModeGuard;

final class ServiceRegistry
{
    private const RESERVED_IDS = [
        ConfigLoader::class,
        self::class,
        ParameterBag::class,
        ReflectionCache::class,
        ServiceFactory::class,
        DependencyValidator::class,
       \Psr\Container\ContainerInterface::class,
       'Zara\Container\Interfaces\ContainerInterface',
       \Zara\Container\Factory\ArgumentResolver::class,
       \Zara\Container\Factory\ParameterResolver::class,
       \Zara\Container\Factory\ServiceInstantiator::class,
       \Zara\Container\Factory\CallExecutor::class,
       \Zara\Container\Registry\CircularDependencyGuard::class,
       \Zara\Container\Registry\DefinitionRegistry::class,
       \Zara\Container\Registry\InstanceStorage::class,
       \Zara\Container\Registry\TagIndex::class,
       \Zara\Container\Registry\AliasRegistry::class,
       \Zara\Container\Registry\ResolutionModeGuard::class,
    ];

    private DefinitionRegistry $definitionRegistry;
    private InstanceStorage $instanceStorage;
    private CircularDependencyGuard $circularGuard;
    private TagIndex $tagIndex;
    private AliasRegistry $aliasRegistry;
   private ResolutionModeGuard $modeGuard;


    public function __construct()
    {
        $this->definitionRegistry = new DefinitionRegistry();
        $this->instanceStorage    = new InstanceStorage();
        $this->circularGuard      = new CircularDependencyGuard();
        $this->tagIndex           = new TagIndex();
        $this->aliasRegistry      = new AliasRegistry();
        $this->modeGuard = new ResolutionModeGuard();
    }

    public function setDefaults(array $defaults): void
    {
        $allowed = ['autowire', 'scope', 'lazy', 'preload', 'public'];
        $unknown = array_diff(array_keys($defaults), $allowed);
        if ($unknown !== []) {
            $suggestions = [];
            foreach ($unknown as $key) {
                $stripped = ltrim($key, '_');
                if (in_array($stripped, $allowed, true)) {
                    $suggestions[] = "'$key' → did you mean '$stripped'?";
                } else {
                    $suggestions[] = "'$key'";
                }
            }
            throw new ContainerException(
                "Container Error: Unknown key(s) in _defaults: " . implode(', ', $suggestions) . '. ' .
                "Allowed keys: " . implode(', ', $allowed) . '.'
            );
        }
        $this->definitionRegistry->setDefaults($defaults);
    }

    public function getDefaults(): array
    {
        return $this->definitionRegistry->getDefaults();
    }

    public function bind(string $id, callable|string|array $callable, string $scope = 'prototype'): void
    {
        $this->assertNotReserved($id);
        $this->assertNotAlias($id);
        
        if (is_array($callable) && (isset($callable['callable']) || isset($callable['class']))) {
            throw new ContainerException(
                "Container Error: '$id' looks like you passed a full definition array to bind(). " .
                "Use define('$id', [...]) instead for definitions with autowire/lazy/calls/tags/public keys."
            );
        }

        if (!in_array($scope, ['singleton', 'prototype'], true)) {
            throw new ContainerException("Container Error: Invalid scope '$scope'. Must be 'singleton' or 'prototype'.");
        }
        
        if (!is_string($callable) && !is_callable($callable) && !(is_array($callable) && count($callable) === 2)) {
            throw new ContainerException("Container Error: Callable for service '$id' must be a class name, callable, or ['@service','method'].");
        }

        $existingArguments = $this->definitionRegistry->hasDefinition($id)
            ? ($this->definitionRegistry->getDefinition($id)['arguments'] ?? [])
            : [];

        $this->definitionRegistry->define($id, [
            'callable'  => $callable,
            'scope'     => $scope,
            'arguments' => $existingArguments,
        ]);
    }

    public function define(string $id, array $definition): void
    {
        if ($id === '') {
            throw new ContainerException('Container Error: Service ID must not be empty.');
        }
        $this->assertNotReserved($id);
        $this->assertNotAlias($id);

        $allowed = ['callable', 'synthetic', 'scope', 'arguments', 'calls', 'tags', 'lazy', 'preload', 'autowire', 'public'];
        $unknown = array_diff(array_keys($definition), $allowed);
        if ($unknown !== []) {
            throw new ContainerException("Container Error: Unknown key(s) in definition for '$id': " . implode(', ', $unknown));
        }

        $isSynthetic = (bool) ($definition['synthetic'] ?? false);

        if ($isSynthetic && isset($definition['callable'])) {
            throw new ContainerException(
                "Container Error: definition for '$id' cannot be both 'synthetic' and have a 'callable' — " .
                "a synthetic service is provided externally via Container::set(), never constructed."
            );
        }

        if (!$isSynthetic && !isset($definition['callable'])) {
            throw new ContainerException(
                "Container Error: definition for '$id' must contain a 'callable' (or be marked 'synthetic' => true)."
            );
        }

        if (isset($definition['scope']) && !in_array($definition['scope'], ['singleton', 'prototype'], true)) {
            throw new ContainerException("Container Error: Invalid scope for '$id'. Must be 'singleton' or 'prototype'.");
        }
        foreach (['arguments', 'calls', 'tags'] as $key) {
            if (isset($definition[$key]) && !is_array($definition[$key])) {
                throw new ContainerException("Container Error: '$key' for service '$id' must be an array.");
            }
        }

        $this->definitionRegistry->define($id, $definition);
    }

    /**
     * Resolves any alias, then reads the 'synthetic' flag from the real
     * definition. Returns false when no definition exists: an undeclared ID
     * is unknown, not synthetic.
     */
    public function isSynthetic(string $id): bool
    {
        $realId = $this->aliasRegistry->has($id) ? $this->aliasRegistry->resolve($id) : $id;

        if (!$this->definitionRegistry->hasDefinition($realId)) {
            return false;
        }

        return (bool) ($this->definitionRegistry->getDefinition($realId)['synthetic'] ?? false);
    }

    /**
     * Declares $aliasId as a simple reference to $targetId: it has no own
     * definition or instance. resolveAlias() translates the ID before
     * ServiceFactory works with the actual service. This prevents two service
     * IDs (a regular ID and an alias) from creating separate instances of a
     * concrete class intended to be a shared singleton.
     */
    public function setAlias(string $aliasId, string $targetId): void
    {
        $this->assertNotReserved($aliasId);

        if ($this->definitionRegistry->hasDefinition($aliasId)) {
            throw new ContainerException(
                "Container Error: '$aliasId' already has a service definition and cannot be redeclared as an alias to '$targetId'."
            );
        }

        if ($this->aliasRegistry->has($aliasId)) {
            throw new ContainerException("Container Error: '$aliasId' is already registered.");
        }
        $realTarget = $this->aliasRegistry->resolve($targetId);
        if ($aliasId === $realTarget) {
            throw new ContainerException("Container Error: Circular alias detected for '$aliasId'.");
        }

        $this->aliasRegistry->set($aliasId, $targetId);
    }

    public function validateAliases(): void
    {
        $this->aliasRegistry->validateTargets(
            fn(string $id): bool => $this->definitionRegistry->hasDefinition($id) || $this->instanceStorage->has($id),
        );
    }

    public function isAlias(string $id): bool
    {
        return $this->aliasRegistry->has($id);
    }

    /**
     * Resolves the alias chain to the real ID. Returns $id unchanged when it
     * is not an alias.
     */
    public function resolveAlias(string $id): string
    {
        return $this->aliasRegistry->resolve($id);
    }

    public function getDefinition(string $id): array
    {
        return $this->definitionRegistry->getDefinition($id);
    }

    public function hasDefinition(string $id): bool
    {
        return $this->definitionRegistry->hasDefinition($id);
    }

    public function setInstance(string $id, object $instance): void
    {
        if ($this->aliasRegistry->has($id)) {
            $id = $this->aliasRegistry->resolve($id);
        }
        if (!$this->definitionRegistry->hasDefinition($id) && !$this->aliasRegistry->has($id)) {
            $this->assertNotReserved($id);
            $this->definitionRegistry->define($id, ['synthetic' => true]);
        }
        $this->instanceStorage->set($id, $instance);
    }

    public function getInstance(string $id): ?object
    {
        return $this->instanceStorage->get($id);
    }

    public function hasInstance(string $id): bool
    {
        return $this->instanceStorage->has($id);
    }

    public function markResolving(string $id): void
    {
        $this->circularGuard->mark($id);
    }

    public function unmarkResolving(string $id): void
    {
        $this->circularGuard->unmark($id);
    }

    public function addTags(string $id, array $tags): void
    {
        $this->tagIndex->addTags($id, $tags);
    }

    public function getTagged(string $tag): array
    {
        return $this->tagIndex->getTagged($tag);
    }

    
    /**
     * Resolves any alias, then reads the 'public' flag from the real
     * definition. A class autowired on demand without a YAML entry (the
     * class_exists() fallback in ArgumentResolver) has no definition to
     * restrict, so it is public by default.
     */
    public function isPublic(string $id): bool
    {
        $realId = $this->aliasRegistry->has($id) ? $this->aliasRegistry->resolve($id) : $id;

        if (!$this->definitionRegistry->hasDefinition($realId)) {
            return true;
        }

        return (bool) ($this->definitionRegistry->getDefinition($realId)['public'] ?? true);
    }

    public function hasResolvableService(string $id): bool
    {
        $realId = $this->aliasRegistry->has($id) ? $this->aliasRegistry->resolve($id) : $id;
        return $this->definitionRegistry->hasDefinition($realId) || $this->instanceStorage->has($realId);
    }

    public function isAccessibleExternally(string $id): bool
    {
        return $this->isPublic($id);
    }

    public function assertCanSetInstance(string $id): void
    {
        $this->assertNotReserved($id);
        $realId = $this->aliasRegistry->has($id) ? $this->aliasRegistry->resolve($id) : $id;
        if ($this->definitionRegistry->hasDefinition($realId)
            && !(bool)($this->definitionRegistry->getDefinition($realId)['synthetic'] ?? false)) {
            throw new ContainerException("Container Error: set() cannot replace constructed service '$id'; register it as synthetic.");
        }
        if ($this->aliasRegistry->has($id) && !$this->definitionRegistry->hasDefinition($realId)) {
            throw new ContainerException("Container Error: cannot provide an instance through alias '$id' before its target is defined.");
        }
    }

    /** @return list<string> */
    public function resolutionStack(): array
    {
        return $this->circularGuard->stack();
    }


    public function enableStrictMode(): void
    {
        $this->modeGuard->enableStrict();
    }

    public function isStrictMode(): bool
    {
        return $this->modeGuard->isStrict();
    }

    /**
     * Automatically registers a minimal definition for an undeclared concrete
     * class if it exists (implicit autowiring). Idempotent: does nothing if a
     * definition or alias already exists. Has no effect in strict mode, where
     * every class must be declared explicitly.
     */
    public function autoRegisterIfPossible(string $id): void
    {
        if ($this->isStrictMode() || $this->hasDefinition($id) || $this->isAlias($id)) {
            return;
        }
      
        $this->assertNotReserved($id);

        if (class_exists($id)) {
            $this->definitionRegistry->define($id, [
                'callable'  => $id,
                'scope'     => 'prototype',
            'arguments' => [],
           ]);
        }
    }

    public function assertAccessibleExternally(string $id): void
    {
        $this->assertNotReserved($id);
    }

    public function getAllServiceIds(): array
    {
        return $this->definitionRegistry->allIds();
    }

    private function assertNotReserved(string $id): void
    {
        if (in_array($id, self::RESERVED_IDS, true)) {
            throw new ContainerException(
                "Container Error: '$id' is an internal service tied to the container's bootstrap. " .
                "It is constructed directly in Container::__construct() before configuration " .
                "is loaded — declaring it in services.yaml has no effect on the real instance and " .
                "would create, if resolved through the container, a disconnected phantom instance " .
                "(never initialized by loadFromFile()). " .
                "Access these services only through Container's public API " .
                "(getParameter(), loadFromFile(), clearReflectionCache()...)."
            );
        }
    }

    private function assertNotAlias(string $id): void
    {
        if ($this->aliasRegistry->has($id)) {
            throw new ContainerException(
                "Container Error: '$id' is already declared as an alias and cannot also have a service definition."
            );
        }
    }
}
