<?php

declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Support\Interfaces;

use Zara\Core\Debug\Profiler\Support\ClassLoadRecord;

interface ClassLoadTrackerInterface
{
    /** @return array<string, ClassLoadRecord> Indexed by class name, insertion order preserved. */
    public function getRecords(): array;

    public function markContainerFresh(string $class, string $serviceId): void;

    public function markContainerReused(string $class, string $serviceId): void;

    /**
     * @return array<string, int> Number of times each service id was
     *                            freshly instantiated (prototype scope
     *                            resolved more than once is a smell).
     */
    public function getResolutionCounts(): array;

    /**
     * Called once at bootstrap, right after services.yaml has been loaded,
     * with every public service id declared in the container.
     *
     * @param list<string> $publicServiceIds
     */
    public function registerDeclaredServices(array $publicServiceIds): void;

    /**
     * @return list<string> Public service ids that were declared but never
     *                       resolved through ServiceFactory::get() on this request.
     */
    public function getUnresolvedDeclaredServices(): array;
}