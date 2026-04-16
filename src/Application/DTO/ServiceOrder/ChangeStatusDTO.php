<?php

declare(strict_types=1);

namespace App\Application\DTO\ServiceOrder;

class ChangeStatusDTO
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $newStatus,
    ) {
    }
}
