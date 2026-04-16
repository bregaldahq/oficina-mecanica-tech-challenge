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
    ) {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
