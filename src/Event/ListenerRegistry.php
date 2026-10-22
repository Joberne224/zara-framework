<?php

declare(strict_types=1);

namespace Zara\Event;

use Psr\EventDispatcher\ListenerProviderInterface;
use Zara\Event\Internal\PrioritizedListener;
use Zara\Event\Interfaces\ListenerInterface;

/**
 * Holds listeners registered per event class, sorted by descending priority.
 * Registration is explicit: the event class is bound at listen() time,
 * never inferred from the event instance's inheritance chain.
 */
final class ListenerRegistry implements ListenerProviderInterface
{
    /** @var array<class-string, PrioritizedListener[]> */
    private array $listeners = [];

    /** @var array<class-string, bool> */
    private array $sorted = [];

    public function listen(string $eventClass, callable|ListenerInterface $listener, int $priority = 0): void
    {
        $this->listeners[$eventClass][] = new PrioritizedListener($listener, $priority);
        $this->sorted[$eventClass] = false;
    }

    public function getListenersForEvent(object $event): iterable
    {
        $eventClass = $event::class;

        if (!isset($this->listeners[$eventClass])) {
            return [];
        }

        if (!$this->sorted[$eventClass]) {
            usort(
                $this->listeners[$eventClass],
                static fn (PrioritizedListener $a, PrioritizedListener $b): int => $b->priority <=> $a->priority,
            );
            $this->sorted[$eventClass] = true;
        }

        return $this->listeners[$eventClass];
    }
}