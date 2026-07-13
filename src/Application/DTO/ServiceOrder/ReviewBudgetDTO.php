<?php

declare(strict_types=1);

namespace App\Application\DTO\ServiceOrder;

class ReviewBudgetDTO
{
    public function __construct(
        public readonly string $orderId,
        public readonly bool $approved,
    ) {
    }
}
