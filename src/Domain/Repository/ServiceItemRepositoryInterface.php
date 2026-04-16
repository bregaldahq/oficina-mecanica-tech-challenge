<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\ServiceItem;

interface ServiceItemRepositoryInterface
{
    public function findById(string $id): ?ServiceItem;

    /** @return ServiceItem[] */
    public function findAll(): array;

    public function save(ServiceItem $serviceItem): void;

    public function update(ServiceItem $serviceItem): void;

    public function delete(string $id): void;

    /** @return array<array{service_item_id: string, description: string, estimated_time_minutes: int, times_used: int}> */
    public function getMetrics(): array;
}
