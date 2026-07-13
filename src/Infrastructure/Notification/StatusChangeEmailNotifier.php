<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Domain\Event\ServiceOrderStatusChangedEvent;
use App\Domain\Notification\MailerInterface;

/**
 * Sends an email to the configured recipient (the workshop's operations inbox)
 * whenever a service order changes status. Best-effort: a delivery failure is
 * logged but never propagated, so it cannot break the status-change request.
 */
class StatusChangeEmailNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $recipient,
    ) {
    }

    public function handle(ServiceOrderStatusChangedEvent $event): void
    {
        if ($this->recipient === '') {
            return;
        }

        $subject = "OS {$event->orderId}: status atualizado para {$event->newStatus}";
        $body    = sprintf(
            "A ordem de serviço %s mudou de %s para %s em %s.",
            $event->orderId,
            $event->previousStatus,
            $event->newStatus,
            $event->occurredAt()->format('Y-m-d H:i:s'),
        );

        try {
            $this->mailer->send($this->recipient, $subject, $body);
        } catch (\Throwable $e) {
            error_log('Failed to send status-change notification: ' . $e->getMessage());
        }
    }
}
