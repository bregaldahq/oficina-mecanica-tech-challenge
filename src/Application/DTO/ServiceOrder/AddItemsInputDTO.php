<?php

declare(strict_types=1);

namespace App\Application\DTO\ServiceOrder;

class AddItemsInputDTO
{
    /**
     * @param string[]                                      $serviceItemIds
     * @param array<array{part_id: string, quantity: int}>  $parts
     */
    public function __construct(
        public readonly string $orderId,
        public readonly array $serviceItemIds = [],
        public readonly array $parts = [],
    ) {
    }
}
