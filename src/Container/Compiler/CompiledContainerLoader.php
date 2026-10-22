<?php
declare(strict_types=1);

namespace Zara\Container\Compiler;

use Zara\Container\ParameterBag;
use Zara\Container\Compiler\Interfaces\ContainerLoaderInterface;
use Zara\Container\ServiceRegistry;
use Zara\Container\ReflectionCache;

/**
 * Single entry point for obtaining a CompiledFactoryInterface at startup:
 * a fresh compiled container returns its actual instance (through the closure
 * exported by the generated file); otherwise, return NullCompiledFactory.
 * The caller (Container) always receives a CompiledFactoryInterface, never
 * null, and does not need to branch on whether compilation is available.
 */
final class CompiledContainerLoader implements ContainerLoaderInterface
{
    public function __construct(
        private CompiledContainerWriter $writer,
        private ?ServiceRegistry $registry = null,
        private ?ReflectionCache $reflectionCache = null,
    ) {}

    public function load(string $compiledPath, int $sourceMtime, ParameterBag $parameters, ?string $expectedFingerprint = null): CompiledFactoryInterface
    {
        if (!$this->writer->isFresh($compiledPath, $sourceMtime)) {
            return new NullCompiledFactory();
        }
        if (!$this->writer->matchesCodeHash($compiledPath)) {
            return new NullCompiledFactory();
        }
        if ($expectedFingerprint === null && $this->registry !== null && $this->reflectionCache !== null) {
            $compiler = new ContainerCompiler($this->registry, $parameters, $this->reflectionCache);
            $expectedFingerprint = hash('sha256', serialize($compiler->plan($this->registry->getAllServiceIds())->compilable()));
        }
        $meta = @unserialize((string)@file_get_contents($compiledPath . '.meta'), ['allowed_classes' => false]);
        if (!is_array($meta) || ($meta['source_mtime'] ?? null) !== $sourceMtime
            || ($expectedFingerprint !== null && !hash_equals($expectedFingerprint, (string)($meta['fingerprint'] ?? '')))) {
            return new NullCompiledFactory();
        }

        try {
            $factoryBuilder = require $compiledPath;
        } catch (\Throwable $e) {
            // Ignore a corrupted compiled file (syntax error, PHP fatal, etc.)
            // instead of breaking bootstrap. This catches failures from
            // require() that cannot reach the checks below.
            return new NullCompiledFactory();
        }

        if (!is_callable($factoryBuilder)) {
            // This may be a compiled file from an older compiler version
            // (the previous format did not return a closure); ignore it too.
            return new NullCompiledFactory();
        }

        try {
            $compiled = $factoryBuilder($parameters);
        } catch (\Throwable) {
            return new NullCompiledFactory();
        }

        return $compiled instanceof CompiledFactoryInterface
            ? $compiled
            : new NullCompiledFactory();
    }
}
