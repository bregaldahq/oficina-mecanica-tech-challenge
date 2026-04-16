<?php

declare(strict_types=1);

namespace App\Domain\Event;

final class ServiceOrderCreatedEvent implements DomainEventInterface
{
    private readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly string $orderId,
        public readonly string $customerId,
        public readonly string $vehicleId,
    ) {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
