<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Entity\Customer;
use App\Domain\Repository\CustomerRepositoryInterface;
use App\Domain\ValueObject\Document;
use App\Infrastructure\Database\PdoConnection;
use PDO;

class PdoCustomerRepository implements CustomerRepositoryInterface
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = PdoConnection::getInstance();
    }

    public function findById(string $id): ?Customer
    {
        $stmt = $this->pdo->prepare('SELECT * FROM customers WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByDocument(string $document): ?Customer
    {
        $stmt = $this->pdo->prepare('SELECT * FROM customers WHERE document = :document');
        $stmt->execute([':document' => $document]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM customers ORDER BY name');
        assert($stmt !== false);
        return array_map([$this, 'hydrate'], $stmt->fetchAll());
    }

    public function save(Customer $customer): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO customers (id, name, document) VALUES (:id, :name, :document)'
        );
        $stmt->execute([
            ':id'       => $customer->getId(),
            ':name'     => $customer->getName(),
            ':document' => $customer->getDocument()->getValue(),
        ]);
    }

    public function update(Customer $customer): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE customers SET name = :name WHERE id = :id'
        );
        $stmt->execute([
            ':name' => $customer->getName(),
            ':id'   => $customer->getId(),
        ]);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM customers WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Customer
    {
        return Customer::create(
            id: $row['id'],
            name: $row['name'],
            document: new Document($row['document']),
        );
    }
}
