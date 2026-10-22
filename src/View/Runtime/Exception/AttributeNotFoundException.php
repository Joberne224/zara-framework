<?php
declare(strict_types=1);

namespace Zara\View\Runtime\Exception;

/**
 * Thrown when a template expression tries to read an attribute (array key,
 * public property, or getter-style method) or call a method that does not
 * exist at all on the given subject.
 *
 * Kept distinct from InaccessibleMemberException for the same reason
 * RouteNotFoundException is kept distinct from RequestMethodNotFoundException
 * in Zara\Core\Routing: "does not exist" and "exists but is not reachable
 * this way" are different failure modes with different causes — a typo in
 * the template versus a deliberate encapsulation boundary — and collapsing
 * them into one generic exception would hide which one a developer is
 * actually looking at.
 */
final class AttributeNotFoundException extends \RuntimeException
{
}