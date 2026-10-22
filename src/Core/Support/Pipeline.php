<?php

declare(strict_types=1);

namespace Zara\Core\Support;

use Closure;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Zara\Core\Debug\Profiler\Support\MiddlewareTracker;
use Zara\Core\Config\Env;
use Zara\Core\Support\Interfaces\PipelineInterface;

/**
 * Passes an object through a stack of executable stages (pipes) sequentially.
 *
 * @package Zara\Core\Support
 */
final class Pipeline implements PipelineInterface
{
    /** @var mixed Object traveling through the pipeline */
    private mixed $passable = null;

    /** @var list<string|Closure|object> List of stages to pass through */
    private array $stages = [];

    /**
     * @param ContainerInterface|null $container PSR-11 dependency injection container instance.
     */
    public function __construct(
        private readonly ?ContainerInterface $container = null
    ) {}

    public function send(mixed $passable): static
    {
        // Clone instance to maintain state immutability across concurrent executions
        $clone = clone $this;
        $clone->passable = $passable;
        $clone->stages = [];

        return $clone;
    }

    public function through(array $stages): static
    {
        $this->stages = $stages;
        return $this;
    }

    public function then(Closure|callable $destination): mixed
    {
        $pipeline = array_reduce(
            array_reverse($this->stages),
            $this->carry(),
            $destination
        );

        return $pipeline($this->passable);
    }

    /**
     * Builds the nested reduction closure for wrapping stages.
     */
    private function carry(): Closure
    {
        return function (callable $stack, mixed $stage): callable {
            return function (mixed $passable) use ($stack, $stage) {
                // Order reserved at entry (before this stage calls downstream),
                // not at completion — see MiddlewareTracker's docblock for why.
                $tracking = Env::isProfilerEnabled();
                $order = $tracking ? MiddlewareTracker::current()->reserveOrder() : 0;
                $label = $tracking ? $this->describeStage($stage) : '';
                $start = $tracking ? microtime(true) : 0.0;
                $failed = false;

                try {
                    // 1. Resolve string class names lazily
                    if (is_string($stage)) {
                        $stage = $this->resolveStage($stage);
                    }

                    // 2. Direct closure or invokable callable execution
                    if (is_callable($stage)) {
                        return $stage($passable, $stack);
                    }

                    // 3. Object middleware executing handle() method
                    if (is_object($stage) && method_exists($stage, 'handle')) {
                        return $stage->handle($passable, $stack);
                    }

                    throw new RuntimeException(
                        sprintf("Pipeline stage [%s] is not executable.", get_debug_type($stage))
                    );
                } catch (\Throwable $e) {
                    $failed = true;
                    throw $e;
                } finally {
                    if ($tracking) {
                        MiddlewareTracker::current()->record(
                            order: $order,
                            label: $label,
                            durationMs: (microtime(true) - $start) * 1000,
                            failed: $failed,
                            startedAt: $start,
                        );
                    }
                }
            };
        };
    }

    /**
     * Human-readable label for a stage, computed from its ORIGINAL form
     * (string class name, closure, or already-instantiated object) — i.e.
     * before resolveStage() turns a string into an instance, since the
     * class name string is already the most readable label available.
     */
    private function describeStage(mixed $stage): string
    {
        if (is_string($stage)) {
            return $stage;
        }

        if ($stage instanceof Closure) {
            return 'Closure';
        }

        if (is_object($stage)) {
            return get_class($stage);
        }

        return get_debug_type($stage);
    }

    /**
     * Resolves a stage instance from the container or creates a new instance.
     */
    private function resolveStage(string $stageClass): object
    {
        if ($this->container?->has($stageClass)) {
            return $this->container->get($stageClass);
        }

        if (class_exists($stageClass)) {
            return new $stageClass();
        }

        throw new RuntimeException(sprintf("Unable to resolve pipeline stage: '%s'.", $stageClass));
    }
}
