<?php

declare(strict_types=1);

namespace Zara\View\Attributes;

/**
 * Marks a public method as explicitly callable from a template via the
 * `object.method(args)` syntax.
 *
 * Without this attribute, AttributeAccessor::call() still enforces its
 * existing checks (public, non-abstract, non-magic) but additionally
 * requires this marker once a MethodCallGuardInterface is configured —
 * closing the gap where any public method on any object present in the
 * template scope was callable, regardless of whether it was intended
 * for view rendering (e.g. a getter) or a domain operation (e.g. save(),
 * delete()) never meant to be reachable from a template.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class TemplateCallable
{
    // Pure marker, no parameters for now — extensible later
    // (e.g. an alias name exposed to the template).
}