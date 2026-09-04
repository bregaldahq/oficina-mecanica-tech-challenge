<?php

declare(strict_types=1);

namespace App\Application\UseCase\ServiceOrder;

use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Repository\ServiceOrderRepositoryInterface;

/**
 * Lists the service orders owned by a single customer.
 * Backs GET /api/service-orders/me, which scopes the result to the `sub` claim of the JWT —
 * replacing the old public lookup by CPF in query string.
 */
class ListServiceOrdersByCustomerUseCase
{
    public function __construct(
        private readonly ServiceOrderRepositoryInterface $repository,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function execute(string $customerId): array
    {
        return array_map(
            fn (ServiceOrder $order) => $order->toArray(),
            $this->repository->findByCustomerId($customerId),
        );
    }
}
