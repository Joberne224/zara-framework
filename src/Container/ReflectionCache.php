<?php
// ===============================================
// File: src/Zara/Container/ReflectionCache.php
// ===============================================

declare(strict_types=1);

namespace Zara\Container;

use Zara\Container\Exceptions\ContainerException;

final class ReflectionCache
{
    /** @var array<string, \ReflectionClass> */
    private array $classCache = [];

    /** @var array<string, array> */
    private array $ctorParamsCache = [];

    private const MAX_CACHE_SIZE = 100;

    public function getClass(string $class): \ReflectionClass
    {
        if (!isset($this->classCache[$class])) {
            try {
                $this->classCache[$class] = new \ReflectionClass($class);
            } catch (\ReflectionException $e) {
                throw new ContainerException("Container Error: Cannot reflect class '$class': {$e->getMessage()}");
            }
            $this->cap();
        }
        return $this->classCache[$class];
    }

    /** @return array<\ReflectionParameter> */
    public function getConstructorParams(string $class): array
    {
        if (!isset($this->ctorParamsCache[$class])) {
            $ctor = $this->getClass($class)->getConstructor();
            $this->ctorParamsCache[$class] = $ctor ? $ctor->getParameters() : [];
            $this->capParams();
        }
        return $this->ctorParamsCache[$class];
    }

    public function clear(): void
    {
        $this->classCache = [];
        $this->ctorParamsCache = [];
    }

    private function cap(): void
    {
        if (count($this->classCache) > self::MAX_CACHE_SIZE) {
            $this->classCache = array_slice($this->classCache, -self::MAX_CACHE_SIZE, null, true);
        }
    }

    private function capParams(): void
    {
        if (count($this->ctorParamsCache) > self::MAX_CACHE_SIZE) {
            $this->ctorParamsCache = array_slice($this->ctorParamsCache, -self::MAX_CACHE_SIZE, null, true);
        }
    }
}