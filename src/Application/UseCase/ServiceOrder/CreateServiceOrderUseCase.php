<?php

declare(strict_types=1);

namespace App\Application\UseCase\ServiceOrder;

use App\Application\DTO\ServiceOrder\CreateServiceOrderInputDTO;
use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\CustomerRepositoryInterface;
use App\Domain\Repository\ServiceOrderRepositoryInterface;
use App\Domain\Repository\VehicleRepositoryInterface;
use App\Domain\UuidGeneratorInterface;

class CreateServiceOrderUseCase
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly VehicleRepositoryInterface $vehicleRepository,
        private readonly ServiceOrderRepositoryInterface $orderRepository,
        private readonly UuidGeneratorInterface $uuidGenerator,
    ) {
    }

    public function execute(CreateServiceOrderInputDTO $input): ServiceOrder
    {
        $customer = $this->customerRepository->findById($input->customerId);
        if ($customer === null) {
            throw new NotFoundException("Cliente não encontrado: {$input->customerId}");
        }

        $vehicle = $this->vehicleRepository->findById($input->vehicleId);
        if ($vehicle === null) {
            throw new NotFoundException("Veículo não encontrado: {$input->vehicleId}");
        }

        if ($vehicle->getCustomerId() !== $input->customerId) {
            throw new DomainException("O veículo não pertence ao cliente informado.");
        }

        $order = ServiceOrder::create(
            id: $this->uuidGenerator->generate(),
            customer: $customer,
            vehicle: $vehicle,
        );

        $this->orderRepository->save($order);

        return $order;
    }
}
