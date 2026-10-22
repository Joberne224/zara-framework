<?php
declare(strict_types=1);

namespace Zara\Container\Compiler;

use Zara\Container\ServiceRegistry;
use Zara\Container\ParameterBag;
use Zara\Container\ReflectionCache;
use Zara\Container\Exceptions\ContainerException;

final class ContainerCompiler
{
    private ServiceCodeGenerator $generator;

    public function __construct(
        private ServiceRegistry $registry,
        ParameterBag $parameters,
        ReflectionCache $refCache,
    ) {
        $this->generator = new ServiceCodeGenerator($registry, $parameters, $refCache);
    }

    public function plan(array $allServiceIds): CompilationPlan
    {
        $plan = new CompilationPlan();

        /** @var array<string, list<string>> id => referenced service IDs */
        $dependencies = [];

        foreach ($allServiceIds as $id) {
            $def = $this->registry->getDefinition($id);

            if (($def['scope'] ?? 'prototype') !== 'singleton') {
                $plan->addExcluded($id, 'scope is not singleton');
                continue;
            }
            if ((bool)($def['lazy'] ?? false)) {
                $plan->addExcluded($id, 'lazy services keep dynamic resolution');
                continue;
            }

            try {
                $this->generator->generate($id, $def); // Dry-run validation.
                $dependencies[$id] = $this->generator->getLastDependencies();
                $plan->addCompilable($id, $def);
            } catch (ContainerException $e) {
                $plan->addExcluded($id, $e->getMessage());
            }
        }

        $this->pruneUncompilableDependencies($plan, $dependencies);

        return $plan;
    }

    /**
     * A compilable service that references (directly or through a dependency
     * chain) a non-compilable service, or participates in a cycle with other
     * compiled services, cannot be compiled: CompiledServiceFactory has no
     * dynamic fallback or cycle detection (unlike ServiceFactory, which uses
     * CircularDependencyGuard). Prune the plan to a fixed point: each pass may
     * exclude more services through cascading dependencies until nothing changes.
     *
     * @param array<string, list<string>> $dependencies
     */
    private function pruneUncompilableDependencies(CompilationPlan $plan, array $dependencies): void
    {
        do {
            $before = count($plan->compilable());

            foreach ($plan->compilable() as $id => $def) {
                foreach ($dependencies[$id] ?? [] as $depId) {
                    if ($depId !== $id && !isset($plan->compilable()[$depId])) {
                        $plan->demote($id, "depends on '$depId', which is not statically compilable");
                        break;
                    }
                }
            }

            $this->demoteCircularDependencies($plan, $dependencies);

            $changed = count($plan->compilable()) !== $before;
        } while ($changed);
    }

    /**
     * Detect cycles using depth-first traversal and a recursion stack, a
     * standard approach for directed graphs. Reaching a node that is still on
     * the stack signals a cycle; exclude every node in that cycle.
     *
     * @param array<string, list<string>> $dependencies
     */
    private function demoteCircularDependencies(CompilationPlan $plan, array $dependencies): void
    {
        $visited = [];
        $stack = [];

        foreach (array_keys($plan->compilable()) as $id) {
            if (!isset($visited[$id])) {
                $this->walkForCycle($id, $dependencies, $plan, $visited, $stack, []);
            }
        }
    }

    /**
     * @param array<string, list<string>> $dependencies
     * @param array<string, true> $visited
     * @param array<string, true> $stack
     * @param list<string> $path
     */
    private function walkForCycle(
        string $id,
        array $dependencies,
        CompilationPlan $plan,
        array &$visited,
        array &$stack,
        array $path,
    ): void {
        if (!isset($plan->compilable()[$id])) {
            return; // Already excluded in a previous pass.
        }

        $visited[$id] = true;
        $stack[$id] = true;
        $path[] = $id;

        foreach ($dependencies[$id] ?? [] as $depId) {
            if (!isset($plan->compilable()[$depId])) {
                continue; // non compilable, pas de cycle possible via ce chemin
            }

            if (isset($stack[$depId])) {
                $cycleStart = array_search($depId, $path, true);
                $cycle = array_slice($path, $cycleStart);
                $cycleDescription = implode(' -> ', $cycle) . " -> $depId";

                foreach ($cycle as $cycleId) {
                    $plan->demote($cycleId, "circular dependency among compiled services: $cycleDescription");
                }

                return;
            }

            if (!isset($visited[$depId])) {
                $this->walkForCycle($depId, $dependencies, $plan, $visited, $stack, $path);
            }
        }

        unset($stack[$id]);
    }

    public function compile(CompilationPlan $plan): string
    {
        $methods = [];

        foreach ($plan->compilable() as $id => $def) {
            $methodName = 'make_' . hash('sha256', $id);
            $body = $this->generator->generate($id, $def);
            $methods[] = <<<PHP
                private function {$methodName}(): object
                {
                    {$body}
                }
                PHP;
        }

        $map = [];
        foreach (array_keys($plan->compilable()) as $id) {
            $methodName = 'make_' . hash('sha256', $id);
            $map[] = var_export($id, true) . " => '$methodName',";
        }

        $methodsCode = implode("\n\n    ", $methods);
        $mapCode = implode("\n        ", $map);

        $fingerprint = hash('sha256', serialize($plan->compilable()));
        return <<<PHP
        <?php

        // Auto-generated by ContainerCompiler. Do not edit.
        declare(strict_types=1);

        namespace Zara\Container\Compiler;

        use Zara\Container\ParameterBag;

        final class CompiledServiceFactory implements CompiledFactoryInterface
        {
            public const FINGERPRINT = '{$fingerprint}';
            private const SERVICE_MAP = [
                {$mapCode}
            ];

            private array \$instances = [];

            public function __construct(private ParameterBag \$parameters) {}

            public function has(string \$id): bool
            {
                return isset(self::SERVICE_MAP[\$id]);
            }

            public function get(string \$id): object
            {
                if (isset(\$this->instances[\$id])) {
                    return \$this->instances[\$id];
                }

                \$method = self::SERVICE_MAP[\$id];
                \$instance = \$this->{\$method}();
                \$this->instances[\$id] = \$instance;

                return \$instance;
            }

            public function set(string \$id, object \$instance): void
            {
                if (isset(self::SERVICE_MAP[\$id])) { \$this->instances[\$id] = \$instance; }
            }

            {$methodsCode}
        }

        return static function (ParameterBag \$parameters): CompiledServiceFactory {
            return new CompiledServiceFactory(\$parameters);
        };
        PHP;
    }
}
