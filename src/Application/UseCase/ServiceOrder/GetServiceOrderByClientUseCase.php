<?php

declare(strict_types=1);

namespace App\Application\UseCase\ServiceOrder;

use App\Domain\Repository\ServiceOrderRepositoryInterface;
use App\Domain\ValueObject\Document;
use App\Domain\ValueObject\LicensePlate;

class GetServiceOrderByClientUseCase
{
    public function __construct(
        private readonly ServiceOrderRepositoryInterface $repository,
    ) {
    }

    public function execute(string $document, string $licensePlate, ?string $status = null): array
    {
        $doc   = new Document($document);
        $plate = new LicensePlate($licensePlate);

        $orders = $this->repository->findByDocumentAndLicensePlate(
            $doc->getValue(),
            $plate->getValue(),
            $status,
        );

        return array_map(fn($o) => $o->toArray(), $orders);
    }
}
