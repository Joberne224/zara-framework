<?php
declare(strict_types=1);

namespace Zara\Container\Compiler;

use Zara\Container\Exceptions\ContainerException;

/**
 * Null Object used when the compiled container is missing or stale.
 * has() always returns false, so CompiledFactoryAdapter always delegates to
 * the dynamic ServiceFactory. Callers (Container and CompiledFactoryAdapter)
 * do not need to know whether a fresh compilation exists.
 */
final class NullCompiledFactory implements CompiledFactoryInterface
{
    public function has(string $id): bool
    {
        return false;
    }

    public function get(string $id): object
    {
        // Should never be called: has() always returns false, so
        // CompiledFactoryAdapter never delegates get() here.
        throw new ContainerException(
            "NullCompiledFactory::get() called for '$id' — has() should have prevented this call."
        );
    }

    public function set(string $id, object $instance): void {}
}
