<?php
declare(strict_types=1);

namespace Zara\Event\Internal;

use Zara\Event\Interfaces\ListenerInterface;

final class PrioritizedListener
{
    /** @var callable */
    private $callable;

    public function __construct(
        callable|ListenerInterface $listener,
        public readonly int $priority,
    ) {
        $this->callable = $listener instanceof ListenerInterface
            ? [$listener, 'handle']
            : $listener;
    }

    public function __invoke(object $event): void
    {
        ($this->callable)($event);
    }
}