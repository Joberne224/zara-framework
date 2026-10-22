<?php
declare(strict_types=1);

namespace Zara\View\Runtime\Exception;

/**
 * Thrown when a template expression targets a member (property or method)
 * that does exist on the subject but cannot be reached from a template:
 * non-public visibility, a static property read through an instance, an
 * uninitialized typed property, or a magic/dunder member (__construct,
 * __call, __get, __toString, ...).
 *
 * See AttributeNotFoundException's docblock for why this is a separate
 * exception rather than a shared "attribute error" type.
 */
final class InaccessibleMemberException extends \RuntimeException
{
}