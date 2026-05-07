<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Entity\Part;
use App\Domain\Repository\PartRepositoryInterface;
use App\Infrastructure\Database\PdoConnection;
use PDO;

class PdoPartRepository implements PartRepositoryInterface
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = PdoConnection::getInstance();
    }

    public function findById(string $id): ?Part
    {
        $stmt = $this->pdo->prepare('SELECT * FROM parts_inventory WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM parts_inventory ORDER BY description');
        assert($stmt !== false);
        return array_map([$this, 'hydrate'], $stmt->fetchAll());
    }

    public function save(Part $part): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO parts_inventory (id, description, price, stock_quantity)
             VALUES (:id, :description, :price, :stock_quantity)'
        );
        $stmt->execute([
            ':id'             => $part->getId(),
            ':description'    => $part->getDescription(),
            ':price'          => $part->getPrice(),
            ':stock_quantity' => $part->getStockQuantity(),
        ]);
    }

    public function update(Part $part): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE parts_inventory SET description = :description, price = :price, stock_quantity = :stock_quantity WHERE id = :id'
        );
        $stmt->execute([
            ':id'             => $part->getId(),
            ':description'    => $part->getDescription(),
            ':price'          => $part->getPrice(),
            ':stock_quantity' => $part->getStockQuantity(),
        ]);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM parts_inventory WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Part
    {
        return Part::create(
            id: $row['id'],
            description: $row['description'],
            price: (float)$row['price'],
            stockQuantity: (int)$row['stock_quantity'],
        );
    }
}
