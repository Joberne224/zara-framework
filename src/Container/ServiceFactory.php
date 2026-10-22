<?php

declare(strict_types=1);

namespace Zara\Container;

use Zara\Container\Factory\ArgumentResolver;
use Zara\Container\Factory\ParameterResolver;
use Zara\Container\Factory\PropertyInjector;
use Zara\Container\Factory\CallExecutor;
use Zara\Container\Factory\ServiceInstantiator;
use Zara\Core\Debug\Profiler\Support\NullClassLoadTracker;
use Zara\Core\Debug\Profiler\Support\ClassLoadTracker;
use Zara\Core\Debug\Profiler\Support\ContainerTracker;
use Zara\Core\Config\Env;
use Psr\Container\ContainerInterface;
use Zara\Container\Interfaces\ServiceFactoryInterface;
use Zara\Container\Exceptions\ContainerException;
use Zara\Container\Exceptions\SyntheticServiceNotProvidedException;

final class ServiceFactory implements ServiceFactoryInterface
{
    private int $resolutionDepth = 0;
    private ServiceInstantiator $instantiator;
    private CallExecutor $callExecutor;

    public function __construct(
        private ContainerInterface $container,
        private ServiceRegistry    $registry,
        private ParameterBag       $parameters,
        private ReflectionCache    $refCache,
    ) {
        $argumentResolver   = new ArgumentResolver($this->container, $this->registry, $this->parameters);
        $parameterResolver  = new ParameterResolver($this->container, $argumentResolver);
        $propertyInjector   = new PropertyInjector($this->container, $argumentResolver);

        $this->instantiator = new ServiceInstantiator(
            $this->container,
            $this->refCache,
            $argumentResolver,
            $parameterResolver,
            $propertyInjector
        );

        $this->callExecutor = new CallExecutor($this->registry, $argumentResolver);
    }

    public function isInsideResolution(): bool
    {
        return $this->resolutionDepth > 0;
    }

    public function get(string $id): mixed
    {
        if ($this->registry->isAlias($id)) {
            return $this->get($this->registry->resolveAlias($id));
        }

        // ContainerTracker wraps everything below: the alias branch above is
        // deliberately excluded (it recurses into get() for the real target
        // id, which gets its own full record — tracking the alias hop too
        // would double-count the same logical resolution under two ids).
        $tracking      = Env::isProfilerEnabled();
        $start         = $tracking ? microtime(true) : 0.0;
        $memoryStart   = $tracking ? memory_get_usage() : 0;
        $reused        = false;
        $failed        = false;
        $resolvedClass = null;

        try {
            if ($instance = $this->registry->getInstance($id)) {
                $reused        = true;
                $resolvedClass = $instance::class;
                ClassLoadTracker::current()->markContainerReused($instance::class, $id);
                return $instance;
            }

            $this->registry->autoRegisterIfPossible($id);

            try {
                $definition = $this->registry->getDefinition($id);
            } catch (ContainerException $e) {
                throw $this->enrichNotFound($id, $e);
            }

            if ($this->registry->isSynthetic($id)) {
                throw new SyntheticServiceNotProvidedException(
                    "Container Error: Service '$id' is synthetic — it must be provided explicitly via " .
                    "Container::set('$id', \$instance) before it is requested. The container will never " .
                    "attempt to construct it."
                );
            }

            $scope = $definition['scope'] ?? 'prototype';

            // NOTE (proxy fix, see diagnosis): 'lazy' no longer has any effect here.
            // Before this fix, lazy: true made the factory return a generic
            // LazyServiceProxy in place of the real service, even for a singleton
            // stored as-is in InstanceStorage. That proxy implements neither the
            // class nor the interface of the service it stands for — only __call()
            // triggers getRealInstance(). Anything that bypasses a method call
            // (direct property access such as `return $this->b;`, instanceof,
            // a strict type-hint in a signature, serialization...) therefore let
            // the proxy leak as-is into the object graph, causing TypeErrors or
            // silently wrong behavior.
            //
            // This container never needed that proxy for lazy loading anyway: a
            // service is only built here when it is actually requested (directly
            // or as a dependency resolved in cascade), unless it carries
            // 'preload: true'. 'lazy: true' is still tolerated in configuration
            // for backward compatibility but no longer changes construction.
            //
            // For the genuinely different case of a reference explicitly deferred
            // until the first method call (e.g. to break a dependency cycle), use
            // the '@lazy:ServiceId' syntax on the argument side — see
            // ArgumentResolver::resolveLazyReference(). That is then a deliberate
            // choice of the caller, who type-hints its property/parameter as
            // LazyServiceProxyInterface instead of the service's concrete type,
            // rather than an implicit behavior silently triggered by the target
            // service's definition.

            $this->resolutionDepth++;
            $this->registry->markResolving($id);

            try {
                $instance = $this->createInstance($id);
                $resolvedClass = $instance::class;
                ClassLoadTracker::current()->markContainerFresh($instance::class, $id);

               // Store the singleton BEFORE running the calls: (avoids infinite
               // recursion) — but still INSIDE the resolutionDepth window, so that
               // resolving the arguments of the calls: is also seen as "internal"
               // by Container::get().
               if ($scope === 'singleton') {
                   $this->registry->setInstance($id, $instance);
               }

               $this->executeCallsDirectly($id, $instance);

               return $instance;
            } finally {
                $this->registry->unmarkResolving($id);
                $this->resolutionDepth--;
            }
        } catch (\Throwable $e) {
            $failed = true;
            throw $e;
        } finally {
            if ($tracking) {
                ContainerTracker::current()->record(
                    id: $id,
                    resolvedClass: $resolvedClass,
                    durationMs: (microtime(true) - $start) * 1000,
                    reused: $reused,
                    failed: $failed,
                    memoryBytes: $reused ? 0 : max(0, memory_get_usage() - $memoryStart),
                    depth: $this->resolutionDepth,
                    startedAt: $start,
                );
            }
        }
    }

    /**
     * Required by the internal API / Container to create bare instances.
     */
    public function createInstance(string $id): object
    {
        $def = $this->registry->getDefinition($id);
        return $this->instantiator->createInstance($id, $def);
    }

    private function enrichNotFound(string $id, ContainerException $previous): ContainerException
    {
        $path = [...$this->registry->resolutionStack(), $id];

        return new ContainerException(
            "Container Error: Cannot resolve service '$id': no definition found.\n" .
            'Resolution path: ' . implode(' -> ', $path),
            previous: $previous,
       );
    }

    /**
     * Wraps the post-creation call execution.
     */
    private function executeCallsDirectly(string $id, object $instance): void
    {
        $def = $this->registry->getDefinition($id);
        $this->callExecutor->executeCalls($id, $instance, $def);
    }
}
