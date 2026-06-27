<?php

declare(strict_types=1);

namespace App\Application\UseCase\ServiceOrder;

use App\Application\DTO\ServiceOrder\AddItemsInputDTO;
use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\PartRepositoryInterface;
use App\Domain\Repository\ServiceItemRepositoryInterface;
use App\Domain\Repository\ServiceOrderRepositoryInterface;

class AddItemsToServiceOrderUseCase
{
    public function __construct(
        private readonly ServiceOrderRepositoryInterface $orderRepository,
        private readonly ServiceItemRepositoryInterface $serviceItemRepository,
        private readonly PartRepositoryInterface $partRepository,
    ) {
    }

    public function execute(AddItemsInputDTO $input): ServiceOrder
    {
        $order = $this->orderRepository->findById($input->orderId);
        if ($order === null) {
            throw new NotFoundException("Ordem de serviço não encontrada: {$input->orderId}");
        }

        foreach ($input->serviceItemIds as $serviceItemId) {
            $item = $this->serviceItemRepository->findById($serviceItemId);
            if ($item === null) {
                throw new NotFoundException("Serviço não encontrado: {$serviceItemId}");
            }
            $order->addService($item);
        }

        foreach ($input->parts as $partData) {
            $part = $this->partRepository->findById($partData['part_id']);
            if ($part === null) {
                throw new NotFoundException("Peça não encontrada: {$partData['part_id']}");
            }
            // addPart validates stock and decrements in-memory
            $order->addPart($part, $partData['quantity']);
        }

        // Persist items + stock decrement (within a transaction). The order keeps its
        // budget (total_amount) current as items are added, so it is stored here too.
        $this->orderRepository->save($order);

        return $order;
    }
}
