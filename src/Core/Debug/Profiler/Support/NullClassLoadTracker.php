<?php

declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Support;

use Zara\Core\Debug\Profiler\Support\Interfaces\ClassLoadTrackerInterface;

final class NullClassLoadTracker implements ClassLoadTrackerInterface
{
    public function getRecords(): array
    {
        return [];
    }

    public function markContainerFresh(string $class, string $serviceId): void
    {
    }

    public function markContainerReused(string $class, string $serviceId): void
    {
    }

    public function getResolutionCounts(): array
    {
        return [];
    }

    public function registerDeclaredServices(array $publicServiceIds): void
    {
    }

    public function getUnresolvedDeclaredServices(): array
    {
        return [];
    }
}