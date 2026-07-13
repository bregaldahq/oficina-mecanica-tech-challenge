<?php

declare(strict_types=1);

namespace App\Application\UseCase\ServiceOrder;

use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Repository\ServiceOrderRepositoryInterface;

class ListServiceOrdersUseCase
{
    public function __construct(
        private readonly ServiceOrderRepositoryInterface $repository,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function execute(): array
    {
        return array_map(
            fn (ServiceOrder $order) => $order->toArray(),
            $this->repository->findActiveOrdered(),
        );
    }
}
