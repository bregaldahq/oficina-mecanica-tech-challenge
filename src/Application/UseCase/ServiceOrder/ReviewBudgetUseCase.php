<?php

declare(strict_types=1);

namespace App\Application\UseCase\ServiceOrder;

use App\Application\DTO\ServiceOrder\ReviewBudgetDTO;
use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Event\EventDispatcherInterface;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\ServiceOrderRepositoryInterface;

/**
 * Applies an external budget decision (approval or rejection) to a service order.
 * Approval releases execution; rejection closes the order without execution.
 */
class ReviewBudgetUseCase
{
    public function __construct(
        private readonly ServiceOrderRepositoryInterface $orderRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function execute(ReviewBudgetDTO $dto): ServiceOrder
    {
        $order = $this->orderRepository->findById($dto->orderId);

        if ($order === null) {
            throw new NotFoundException("Ordem de serviço não encontrada: {$dto->orderId}");
        }

        if ($dto->approved) {
            $order->approve();
        } else {
            $order->reject();
        }

        $this->orderRepository->updateStatus($order);
        $this->eventDispatcher->dispatchAll($order->releaseEvents());

        return $order;
    }
}
