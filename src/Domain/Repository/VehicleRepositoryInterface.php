<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Vehicle;

interface VehicleRepositoryInterface
{
    public function findById(string $id): ?Vehicle;

    public function findByLicensePlate(string $plate): ?Vehicle;

    /** @return Vehicle[] */
    public function findByCustomerId(string $customerId): array;

    /** @return Vehicle[] */
    public function findAll(): array;

    public function save(Vehicle $vehicle): void;

    public function update(Vehicle $vehicle): void;

    public function delete(string $id): void;
}
