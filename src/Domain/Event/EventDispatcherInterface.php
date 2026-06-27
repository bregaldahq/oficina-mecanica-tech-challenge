<?php

declare(strict_types=1);

namespace App\Domain\Event;

interface EventDispatcherInterface
{
    public function dispatch(DomainEventInterface $event): void;

    /** @param DomainEventInterface[] $events */
    public function dispatchAll(array $events): void;
}
