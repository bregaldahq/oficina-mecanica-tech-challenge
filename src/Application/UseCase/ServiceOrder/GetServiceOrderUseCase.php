<?php

declare(strict_types=1);

namespace App\Application\UseCase\ServiceOrder;

use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\ServiceOrderRepositoryInterface;

class GetServiceOrderUseCase
{
    public function __construct(
        private readonly ServiceOrderRepositoryInterface $repository,
    ) {
    }

    public function execute(string $id): ServiceOrder
    {
        $order = $this->repository->findById($id);

        if ($order === null) {
            throw new NotFoundException("Ordem de serviço não encontrada: {$id}");
        }

        return $order;
    }
}
