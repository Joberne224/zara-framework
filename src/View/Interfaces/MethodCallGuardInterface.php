<?php

declare(strict_types=1);

namespace Zara\View\Interfaces;

/**
 * Decides whether a given public method may be invoked from a template
 * via AttributeAccessor::call(). Injected as an optional collaborator
 * so AttributeAccessor's existing checks (public, non-abstract,
 * non-magic) remain unaffected when no guard is configured.
 */
interface MethodCallGuardInterface
{
    public function isAllowed(object $subject, string $method): bool;
}