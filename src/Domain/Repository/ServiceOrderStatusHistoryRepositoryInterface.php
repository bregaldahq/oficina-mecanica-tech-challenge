<?php

declare(strict_types=1);

namespace App\Domain\Repository;

/**
 * Append-only audit trail of service order status transitions
 * (table service_order_status_history, CONTRATOS.md §6).
 */
interface ServiceOrderStatusHistoryRepositoryInterface
{
    public function append(
        string $id,
        string $serviceOrderId,
        ?string $fromStatus,
        string $toStatus,
        \DateTimeImmutable $changedAt,
        ?string $changedBy,
    ): void;

    /**
     * Instant of the last transition recorded strictly before $before.
     *
     * Taking "before" as a parameter (instead of "the latest") keeps the answer independent
     * of whether the history row for the current transition has already been written,
     * so subscriber registration order does not change the result.
     */
    public function findLastChangedAtBefore(string $serviceOrderId, \DateTimeImmutable $before): ?\DateTimeImmutable;
}
