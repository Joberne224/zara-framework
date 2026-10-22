<?php
declare(strict_types=1);

namespace Zara\Core\Debug;

/**
 * Reflection-based inspection of an object, shared by every VarDumper output
 * (HTML, CLI, JSON). Reads structure only: it never initializes a lazy object.
 */
final class ObjectInspector
{
    private const MAX_ARRAY_DEPTH = 64;
    /**
     * Instance properties only: ReflectionClass::getProperties() also lists
     * static ones, and omits the private properties of parent classes.
     *
     * @return list<\ReflectionProperty>
     */
    public static function properties(object $object): array
    {
        $properties = [];

        for ($class = new \ReflectionObject($object); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $prop) {
                // getProperties() repeats inherited public/protected ones: keep each
                // property once, when walking the class that declares it.
                if (!$prop->isStatic() && $prop->getDeclaringClass()->getName() === $class->getName()) {
                    $properties[] = $prop;
                }
            }
        }

        return $properties;
    }

    /**
     * PHP 8.4 native lazy object whose initializer has not run yet. Reading any
     * of its properties would run it, a side effect a debug helper must not have.
     */
    public static function isUninitializedLazy(object $object): bool
    {
        $reflection = new \ReflectionObject($object);

        return method_exists($reflection, 'isUninitializedLazyObject')
            && $reflection->isUninitializedLazyObject($object);
    }

    /**
     * @return list<string> readonly, final, abstract, anonymous
     */
    public static function flags(object $object): array
    {
        $reflection = new \ReflectionObject($object);
        $flags      = [];

        if ($reflection->isReadOnly()) {
            $flags[] = 'readonly';
        }
        if ($reflection->isFinal()) {
            $flags[] = 'final';
        }
        if ($reflection->isAbstract() && !$reflection->isAnonymous()) {
            $flags[] = 'abstract';
        }
        if ($reflection->isAnonymous()) {
            $flags[] = 'anonymous';
        }

        return $flags;
    }

    /**
     * Number of unique sub-objects below the object. Serialized size is not
     * estimated because that could run user-defined hooks during inspection.
     *
     * @return array{size: string, dependencies: int}
     */
    public static function metrics(object $object): array
    {
        $count = self::walkDependencies($object);

        return [
            'size'         => 'N/A',
            'dependencies' => $count,
        ];
    }

    /**
     * Uninitialized lazy objects are counted but never entered.
     *
     * @return int
     */
    private static function walkDependencies(object $root): int
    {
        $seen = new \SplObjectStorage();
        $seen->offsetSet($root);

        $queue   = [$root];
        $count   = 0;
        while (!empty($queue)) {
            $current = array_shift($queue);

            if (self::isUninitializedLazy($current)) {
                continue;
            }

            foreach (self::properties($current) as $prop) {
                if (!$prop->isInitialized($current)) {
                    continue;
                }

                self::collectDependencies($prop->getValue($current), $seen, $queue, $count);
            }
        }

        return $count;
    }

    private static function collectDependencies(mixed $value, \SplObjectStorage $seen, array &$queue, int &$count, int $arrayDepth = 0): void
    {
        if (is_object($value)) {
            if (!$seen->offsetExists($value)) {
                $seen->offsetSet($value);
                $count++;
                $queue[] = $value;
            }
        } elseif (is_array($value)) {
            // Recursive PHP arrays can reference themselves. Bound their
            // nesting so malformed or cyclic values cannot exhaust the stack.
            if ($arrayDepth >= self::MAX_ARRAY_DEPTH) {
                return;
            }

            foreach ($value as $item) {
                self::collectDependencies($item, $seen, $queue, $count, $arrayDepth + 1);
            }
        }
    }
}
