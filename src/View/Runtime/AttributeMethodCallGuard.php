<?php

declare(strict_types=1);

namespace Zara\View\Runtime;

use Zara\View\Attributes\TemplateCallable;
use Zara\View\Interfaces\MethodCallGuardInterface;

/**
 * Default MethodCallGuardInterface implementation: a method is allowed
 * from a template only if it is marked with #[TemplateCallable].
 *
 * Results are cached per "Class::method" pair since ReflectionMethod
 * construction and attribute lookup are not free, and the same
 * subject/method pair is commonly resolved repeatedly across a single
 * render pass (e.g. inside a loop).
 */
final class AttributeMethodCallGuard implements MethodCallGuardInterface
{
    /** @var array<string, bool> */
    private array $cache = [];

    public function isAllowed(object $subject, string $method): bool
    {
        $key = $subject::class . '::' . $method;

        return $this->cache[$key] ??= $this->resolve($subject, $method);
    }

    private function resolve(object $subject, string $method): bool
    {
        $reflection = new \ReflectionMethod($subject, $method);

        return $reflection->getAttributes(TemplateCallable::class) !== [];
    }
}