<?php

declare(strict_types=1);

namespace App\Application\DTO\ServiceOrder;

class CreateServiceOrderInputDTO
{
    public function __construct(
        public readonly string $customerId,
        public readonly string $vehicleId,
    ) {
    }
}
