<?php
declare(strict_types=1);

namespace Zara\Container\Compiler;

use Zara\Container\ParameterBag;
use Zara\Container\ServiceRegistry;
use Zara\Container\ReflectionCache;
use Zara\Container\Compiler\Interfaces\ContainerLoaderInterface;

/**
 * Decorates a ContainerLoaderInterface: if the compiled cache is stale or
 * missing, recompiles it immediately before delegating to the wrapped loader.
 *
 * Intended for local development, never production, where recompiling on a
 * user request would be slow and risky (disk writes triggered by uncontrolled
 * traffic). The bootstrap layer chooses whether to use it; this class simply
 * executes the strategy it was given.
 *
 * If recompilation fails (for example, an accidentally added non-compilable
 * service or a full disk), the request does not fail: the inner loader falls
 * back to NullCompiledFactory, just as it would for a missing cache.
 */
final class AutoRecompilingContainerLoader implements ContainerLoaderInterface
{
    public function __construct(
        private ContainerLoaderInterface $inner,
        private ContainerCompiler $compiler,
        private CompiledContainerWriter $writer,
        private ServiceRegistry $registry,
        private ReflectionCache $reflectionCache,
    ) {}

    public function load(string $compiledPath, int $sourceMtime, ParameterBag $parameters, ?string $expectedFingerprint = null): CompiledFactoryInterface
    {
        $plan = $this->compiler->plan($this->registry->getAllServiceIds());
        $expectedFingerprint ??= hash('sha256', serialize($plan->compilable()));
        $fresh = $this->writer->isFresh($compiledPath, $sourceMtime);
        if ($fresh) {
            $fresh = $this->writer->matchesCodeHash($compiledPath);
        }
        if ($fresh && $expectedFingerprint !== null) {
            $meta = @unserialize((string)@file_get_contents($compiledPath . '.meta'), ['allowed_classes' => false]);
            $fresh = is_array($meta) && ($meta['fingerprint'] ?? null) === $expectedFingerprint;
        }
        if (!$fresh) {
            $this->recompile($compiledPath, $sourceMtime, $expectedFingerprint, $plan);
        }

        return $this->inner->load($compiledPath, $sourceMtime, $parameters, $expectedFingerprint);
    }

    private function recompile(string $compiledPath, int $sourceMtime, ?string $expectedFingerprint, CompilationPlan $plan): void
    {
        try {
            $code = $this->compiler->compile($plan);
            $fingerprint = hash('sha256', serialize($plan->compilable()));
            $this->writer->write($compiledPath, $code, $sourceMtime, $fingerprint);
        } catch (\Throwable $e) {
            // The fallback remains the same (the inner load above will return
            // NullCompiledFactory), but do not swallow the error silently: in
            // development, a recompilation failure should be visible.
            error_log(sprintf(
                '[Zara Container] Automatic recompilation failed for %s: %s',
                $compiledPath,
                $e->getMessage(),
            ));
        }
    }
}
