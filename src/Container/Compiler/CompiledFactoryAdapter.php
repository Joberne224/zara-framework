<?php

declare(strict_types=1);

namespace Zara\Container\Compiler;

use Zara\Container\ServiceFactory;
use Zara\Container\ServiceRegistry;
use Zara\Container\ParameterBag;
use Zara\Container\Interfaces\ServiceFactoryInterface;

/**
 * Combines the generated CompiledServiceFactory (fast and reflection-free,
 * but limited to singleton, non-lazy, statically resolvable services) with the
 * existing dynamic ServiceFactory (which handles everything else: prototypes,
 * lazy services, custom callables, and !tagged:). Container::get() sees no
 * difference between the two paths.
 */
final class CompiledFactoryAdapter implements ServiceFactoryInterface
{
    public function __construct(
        private CompiledFactoryInterface $compiled,
        private ServiceFactory $dynamicFallback,
        private ServiceRegistry $registry,
        private ParameterBag $parameters,
    ) {}

    public function isInsideResolution(): bool
    {
        return $this->dynamicFallback->isInsideResolution();
    }

    public function get(string $id): mixed
    {
        $realId = $this->registry->resolveAlias($id);
        if ($this->compiled->has($realId) && !$this->registryIsPrivate($realId)
            && !$this->registry->hasInstance($realId)) {
            return $this->compiled->get($realId);
        }
        return $this->dynamicFallback->get($id);
    }

    private function registryIsPrivate(string $id): bool
    {
        return !$this->registry->isPublic($id);
    }

    public function setInstance(string $id, object $instance): void
    {
        $this->compiled->set($id, $instance);
    }

    public function createInstance(string $id): object
    {
        // Used internally by ServiceFactory for lazy cases, never by a
        // compiled service (compilation already excludes lazy scope), so
        // always delegate to the dynamic factory.
        return $this->dynamicFallback->createInstance($id);
    }
}
