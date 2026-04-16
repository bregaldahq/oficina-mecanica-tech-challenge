<?php

declare(strict_types=1);

namespace App\Infrastructure\Event;

use App\Domain\Event\DomainEventInterface;

class InMemoryEventDispatcher
{
    /** @var array<string, callable[]> */
    private array $listeners = [];

    public function subscribe(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    public function dispatch(DomainEventInterface $event): void
    {
        $eventClass = get_class($event);
        foreach ($this->listeners[$eventClass] ?? [] as $listener) {
            $listener($event);
        }
    }

    /** @param DomainEventInterface[] $events */
    public function dispatchAll(array $events): void
    {
        foreach ($events as $event) {
            $this->dispatch($event);
        }
    }
}
