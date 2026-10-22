<?php
declare(strict_types=1);

namespace Zara\Container\Interfaces;

/**
 * Contract implemented by both ServiceFactory (dynamic resolution through
 * Reflection) and CompiledFactoryAdapter (compiled resolution with a dynamic
 * fallback). Container::$factory depends on this interface rather than the
 * concrete ServiceFactory class, so it can use either implementation whether
 * a compiled container is available or not.
 */
interface ServiceFactoryInterface
{
    public function get(string $id): mixed;

    public function isInsideResolution(): bool;

    public function createInstance(string $id): object;
}
