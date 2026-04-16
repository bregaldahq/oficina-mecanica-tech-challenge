<?php

declare(strict_types=1);

namespace App\Application\UseCase\ServiceOrder;

use App\Application\DTO\ServiceOrder\ChangeStatusDTO;
use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\ServiceOrderRepositoryInterface;

class ChangeServiceOrderStatusUseCase
{
    public function __construct(
        private readonly ServiceOrderRepositoryInterface $orderRepository,
    ) {
    }

    public function execute(ChangeStatusDTO $dto): ServiceOrder
    {
        $order = $this->orderRepository->findById($dto->orderId);

        if ($order === null) {
            throw new NotFoundException("Ordem de serviço não encontrada: {$dto->orderId}");
        }

        match ($dto->newStatus) {
            'DIAGNOSIS'         => $order->changeToDiagnosis(),
            'AWAITING_APPROVAL' => $order->sendForApproval(),
            'EXECUTING'         => $order->approve(),
            'FINISHED'          => $order->finish(),
            'DELIVERING',
            'DELIVERED'         => $order->deliver(),
            default             => throw new DomainException("Status inválido: {$dto->newStatus}"),
        };

        $this->orderRepository->updateStatus($order);

        return $order;
    }
}
