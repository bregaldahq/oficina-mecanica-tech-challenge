<?php

declare(strict_types=1);

namespace App\Infrastructure\Event\Subscriber;

use App\Domain\Event\ServiceOrderCreatedEvent;
use App\Domain\Event\ServiceOrderStatusChangedEvent;
use App\Domain\Repository\ServiceOrderStatusHistoryRepositoryInterface;
use App\Domain\UuidGeneratorInterface;
use App\Infrastructure\Context\RequestContext;
use App\Infrastructure\Logging\JsonLogger;

/**
 * Persists every status transition into service_order_status_history.
 *
 * The history is the source of truth for the "time in status" metrics of the New Relic
 * dashboards and for auditing who moved an order. A failure to write it must never break
 * the business operation, so errors are logged and swallowed.
 */
final class StatusHistorySubscriber
{
    public function __construct(
        private readonly ServiceOrderStatusHistoryRepositoryInterface $repository,
        private readonly UuidGeneratorInterface $uuidGenerator,
        private readonly RequestContext $context,
        private readonly ?JsonLogger $logger = null,
    ) {
    }

    /** The creation of an order is its first transition: (null -> RECEIVED). */
    public function onCreated(ServiceOrderCreatedEvent $event): void
    {
        $this->record($event->orderId, null, 'RECEIVED', $event->occurredAt());
    }

    public function onStatusChanged(ServiceOrderStatusChangedEvent $event): void
    {
        $this->record($event->orderId, $event->previousStatus, $event->newStatus, $event->occurredAt());
    }

    private function record(string $orderId, ?string $from, string $to, \DateTimeImmutable $at): void
    {
        try {
            $this->repository->append(
                $this->uuidGenerator->generate(),
                $orderId,
                $from,
                $to,
                $at,
                $this->context->getActor(),
            );
        } catch (\Throwable $e) {
            $this->logger?->error('status_history.write_failed', [
                'correlation_id'    => $this->context->getCorrelationId(),
                'order_id'          => $orderId,
                'to_status'         => $to,
                'exception_class'   => $e::class,
                'exception_message' => $e->getMessage(),
            ]);
        }
    }
}
