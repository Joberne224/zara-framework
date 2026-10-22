<?php

declare(strict_types=1);

namespace Zara\Event\Interfaces;

/**
 * A class-based listener reacting to a specific event type.
 * Registered listeners may implement this interface, or be any PHP callable.
 */
interface ListenerInterface
{
    public function handle(object $event): void;
}