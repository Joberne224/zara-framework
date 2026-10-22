<?php
declare(strict_types=1);

namespace Zara\Container\Exceptions;

/**
 * Thrown when a service marked `synthetic: true` is requested through
 * Container::get() before it has been provided through Container::set().
 * Kept distinct from the generic ContainerException so callers can tell it
 * apart from a regular "service not found" error.
 */
final class SyntheticServiceNotProvidedException extends ContainerException
{
}
