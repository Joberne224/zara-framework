<?php
declare(strict_types=1);

namespace Zara\View\Runtime;

use Zara\View\Runtime\Exception\AttributeNotFoundException;
use Zara\View\Runtime\Exception\InaccessibleMemberException;

/**
 * Backs the `is defined` / `is not defined` template test compiled by
 * VariableExpressionResolver (see its class docblock, "Feature added").
 *
 * AttributeAccessor::get()/call() are deliberately loud: a missing array
 * key, object property, getter, or explicit method throws rather than
 * returning null — the right default for an ordinary {{ user.name }}
 * read. `is defined` exists precisely so a template can ask "does this
 * path resolve?" without triggering that loud failure for a case the
 * author already expects might be absent.
 *
 * This class is the single, narrow place where that happens: it
 * re-runs the exact same AttributeAccessor calls the normal read would
 * use (handed in as a closure, not reimplemented here) and converts
 * ONLY the two "missing member" exceptions into `false`. Nothing else
 * is swallowed — a getter that itself throws, say, \RuntimeException
 * because of an unrelated bug still propagates exactly as loudly as it
 * would from a plain {{ }} read. `is defined` answers "is this member
 * present", not "did evaluating this expression succeed for any
 * reason".
 *
 * Not meant to be called directly from templates; VariableExpressionResolver
 * is the only intended caller of check().
 */
final class DefinedGuard
{
    public static function check(\Closure $resolver): bool
    {
        try {
            $resolver();
            return true;
        } catch (AttributeNotFoundException | InaccessibleMemberException) {
            return false;
        }
    }
}
