<?php

declare(strict_types=1);

namespace App\Domain\Event;

interface DomainEventInterface
{
    public function occurredAt(): \DateTimeImmutable;
}
