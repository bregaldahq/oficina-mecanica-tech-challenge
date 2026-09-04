<?php

declare(strict_types=1);

namespace App\Domain\Event;

final class ServiceOrderStatusChangedEvent implements DomainEventInterface
{
    private readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly string $orderId,
        public readonly string $previousStatus,
        public readonly string $newStatus,
        /** Order total at the moment of the transition — feeds the revenue widgets. */
        public readonly float $totalAmount = 0.00,
    ) {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
