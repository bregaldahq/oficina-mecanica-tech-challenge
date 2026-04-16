<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Entity\Vehicle;
use App\Domain\Repository\VehicleRepositoryInterface;
use App\Domain\ValueObject\LicensePlate;
use App\Infrastructure\Database\PdoConnection;
use PDO;

class PdoVehicleRepository implements VehicleRepositoryInterface
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = PdoConnection::getInstance();
    }

    public function findById(string $id): ?Vehicle
    {
        $stmt = $this->pdo->prepare('SELECT * FROM vehicles WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByLicensePlate(string $plate): ?Vehicle
    {
        $stmt = $this->pdo->prepare('SELECT * FROM vehicles WHERE license_plate = :plate');
        $stmt->execute([':plate' => $plate]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByCustomerId(string $customerId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM vehicles WHERE customer_id = :customer_id');
        $stmt->execute([':customer_id' => $customerId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll());
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM vehicles ORDER BY brand, model');
        return array_map([$this, 'hydrate'], $stmt->fetchAll());
    }

    public function save(Vehicle $vehicle): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO vehicles (id, customer_id, license_plate, brand, model, year)
             VALUES (:id, :customer_id, :license_plate, :brand, :model, :year)'
        );
        $stmt->execute([
            ':id'            => $vehicle->getId(),
            ':customer_id'   => $vehicle->getCustomerId(),
            ':license_plate' => $vehicle->getLicensePlate()->getValue(),
            ':brand'         => $vehicle->getBrand(),
            ':model'         => $vehicle->getModel(),
            ':year'          => $vehicle->getYear(),
        ]);
    }

    public function update(Vehicle $vehicle): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE vehicles SET brand = :brand, model = :model, year = :year WHERE id = :id'
        );
        $stmt->execute([
            ':brand' => $vehicle->getBrand(),
            ':model' => $vehicle->getModel(),
            ':year'  => $vehicle->getYear(),
            ':id'    => $vehicle->getId(),
        ]);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM vehicles WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    private function hydrate(array $row): Vehicle
    {
        return Vehicle::create(
            id: $row['id'],
            customerId: $row['customer_id'],
            licensePlate: new LicensePlate($row['license_plate']),
            brand: $row['brand'],
            model: $row['model'],
            year: (int)$row['year'],
        );
    }
}
