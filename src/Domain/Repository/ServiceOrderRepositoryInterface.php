<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Aggregate\ServiceOrder;

interface ServiceOrderRepositoryInterface
{
    public function findById(string $id): ?ServiceOrder;

    /** @return ServiceOrder[] */
    public function findAll(): array;

    /** @return ServiceOrder[] */
    public function findByDocumentAndLicensePlate(string $document, string $licensePlate, ?string $status = null): array;

    /**
     * Upsert: creates or updates the order.
     * When items are present, also inserts into service_order_services/parts
     * and decrements parts_inventory.stock_quantity (within a transaction).
     */
    public function save(ServiceOrder $order): void;

    public function updateStatus(ServiceOrder $order): void;
}
