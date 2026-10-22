<?php
declare(strict_types=1);

namespace Zara\Container\Factory;

use Zara\Container\ServiceRegistry;
use Zara\Container\Exceptions\ContainerException;

final class CallExecutor
{
    public function __construct(
        private ServiceRegistry $registry,
        private ArgumentResolver $argumentResolver,
    ) {}

    public function executeCalls(string $id, object $instance, array $def): void
    {
        if (!isset($def['calls']) || !is_array($def['calls'])) {
            return;
        }

        foreach ($def['calls'] as $call) {
            if (!is_array($call) || count($call) < 2) {
                continue;
            }

            $methodName = $call[0];
            $callArguments = $call[1];

            if (!is_string($methodName) || !is_array($callArguments)) {
                continue;
            }

            $taggedKeys = [];
            foreach ($callArguments as $k => $v) {
                if (is_string($v) && str_starts_with($v, '!tagged:')) {
                    $taggedKeys[$k] = substr($v, 8);
                }
            }

            foreach ($callArguments as $k => $v) {
                if (is_string($v) && str_starts_with($v, 'tagged:') && !str_starts_with($v, '!tagged:')) {
                    throw new ContainerException(
                        "Container Error: Suspicious argument '#$k' in call '$methodName' on service '$id': " .
                        "value '$v' looks like a tagged reference but is missing the '!' prefix. " .
                        "Did you mean '!$v'?"
                    );
                }
            }

            if ($taggedKeys !== []) {
                $this->expandAndExecuteCalls($id, $instance, $methodName, $callArguments, $taggedKeys);
            } else {
                $resolvedArgs = $this->argumentResolver->resolveArguments($callArguments, "in call '$methodName' on service '$id'");
                $instance->$methodName(...$resolvedArgs);
            }
        }
    }

    private function expandAndExecuteCalls(
        string $id,
        object $instance,
        string $methodName,
        array $callArguments,
        array $taggedKeys,
    ): void {
        $taggedInstances = [];
        foreach ($taggedKeys as $k => $tagName) {
            $serviceIds = $this->registry->getTagged($tagName);
            $taggedInstances[$k] = [];
            foreach ($serviceIds as $sid) {
                $taggedInstances[$k][] = $this->argumentResolver->resolveSingleService($sid);
            }
        }

        $firstKey = array_key_first($taggedKeys);
        $count = count($taggedInstances[$firstKey]);

        foreach ($taggedInstances as $k => $instances) {
            if (count($instances) !== $count) {
                throw new ContainerException(
                    "Container Error: In call '$methodName' on service '$id', tagged arguments have " .
                    "mismatched counts: '{$taggedKeys[$firstKey]}' resolved to $count instance(s), " .
                    "'{$taggedKeys[$k]}' resolved to " . count($instances) . ". " .
                    "All tagged arguments in the same call must resolve to the same number of instances."
                );
            }
        }

        if ($count === 0) {
            return;
        }

        $staticArgs = [];
        foreach ($callArguments as $k => $rawArg) {
            if (!isset($taggedKeys[$k])) {
                $resolved = $this->argumentResolver->resolveArguments([$k => $rawArg], "in call '$methodName' on service '$id'");
                $staticArgs[$k] = $resolved[$k];
            }
        }

        for ($i = 0; $i < $count; $i++) {
            $args = $staticArgs;
            foreach ($taggedKeys as $k => $_tagName) {
                $args[$k] = $taggedInstances[$k][$i] ?? null;
            }
            if (array_filter(array_keys($args), static fn($key): bool => is_string($key) && !ctype_digit($key)) !== []) {
                $instance->$methodName(...$args);
            } else {
                ksort($args);
                $instance->$methodName(...array_values($args));
            }
        }
    }
}
