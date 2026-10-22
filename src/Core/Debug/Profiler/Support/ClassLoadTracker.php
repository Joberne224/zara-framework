<?php

declare(strict_types=1);

namespace Zara\Core\Debug\Profiler\Support;

use Zara\Core\Debug\Profiler\Support\Interfaces\ClassLoadTrackerInterface;

/**
 * Intercepts the autoload chain to record every class actually referenced
 * during the current request (new, instanceof, extends, static call...).
 *
 * Must be installed BEFORE vendor/autoload.php is required (or at least
 * before anything else is autoloaded), so it sits first in the autoload
 * queue and sees every class before Composer loads it. In practice: the
 * very first line of public/index.php.
 *
 * ServiceFactory is an internal service (constructed directly inside
 * Container::__construct(), before services.yaml is even read — see
 * ServiceRegistry::RESERVED_IDS), so it cannot receive this tracker via
 * constructor injection. Instead it reads the static current() accessor,
 * the same pattern Profiler already uses for the ZARA_START constant.
 */
final class ClassLoadTracker implements ClassLoadTrackerInterface
{
    private static ?self $instance = null;

    /** @var array<string, ClassLoadRecord> */
    private array $records = [];
    private int $sequence = 0;
    private readonly float $startedAt;

    /** @var list<string> */
    private array $declaredPublicServiceIds = [];

    /** @var array<string, true> */
    private array $resolvedServiceIds = [];

    /** @var array<string, int> */
    private array $resolutionCounts = [];

    // Guards against ClassLoadRecord/ClassLoadSource's own autoload
    // re-entering record() while we're still constructing the first record.
    private bool $recording = false;

    private function __construct()
    {
        $this->startedAt = microtime(true);
    }

    public static function install(): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $tracker = new self();
        self::$instance = $tracker;

        spl_autoload_register(
            static function (string $class) use ($tracker): void {
                $tracker->record($class);
            },
            prepend: true,
        );

        return $tracker;
    }

    public static function current(): ClassLoadTrackerInterface
    {
        return self::$instance ?? new NullClassLoadTracker();
    }

    private function record(string $class): void
    {
        if ($this->recording || isset($this->records[$class])) {
            return;
        }

        $this->recording = true;
        try {
            $this->records[$class] = new ClassLoadRecord(
                class: $class,
                order: $this->sequence++,
                timeOffsetMs: (microtime(true) - $this->startedAt) * 1000,
                memoryDeltaBytes: memory_get_usage(),
            );
        } finally {
            $this->recording = false;
        }
    }

    public function markContainerFresh(string $class, string $serviceId): void
    {
        $this->tag($class, $serviceId, ClassLoadSource::ContainerFresh);
        $this->resolutionCounts[$serviceId] = ($this->resolutionCounts[$serviceId] ?? 0) + 1;
    }

    public function markContainerReused(string $class, string $serviceId): void
    {
        $this->tag($class, $serviceId, ClassLoadSource::ContainerReused);
    }

    public function getResolutionCounts(): array
    {
        return $this->resolutionCounts;
    }

    private function tag(string $class, string $serviceId, ClassLoadSource $source): void
    {
        $this->record($class); // in case autoload never fired (already-declared/built-in class)
        $this->records[$class]->source = $source;
        $this->records[$class]->serviceId = $serviceId;
        $this->resolvedServiceIds[$serviceId] = true;
    }

    public function getRecords(): array
    {
        return $this->records;
    }

    public function registerDeclaredServices(array $publicServiceIds): void
    {
        $this->declaredPublicServiceIds = $publicServiceIds;
    }

    public function getUnresolvedDeclaredServices(): array
    {
        return array_values(array_diff($this->declaredPublicServiceIds, array_keys($this->resolvedServiceIds)));
    }
}