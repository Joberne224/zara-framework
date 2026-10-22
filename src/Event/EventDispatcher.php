<?php
declare(strict_types=1);

namespace Zara\Event;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

final class EventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        private readonly ListenerProviderInterface $listeners,
    ) {
    }

    public function dispatch(object $event): object
    {
        foreach ($this->listeners->getListenersForEvent($event) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }

            $listener($event);
        }

        return $event;
    }
}