<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Entity\Customer;
use App\Domain\Repository\CustomerRepositoryInterface;
use App\Domain\ValueObject\CustomerStatus;
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
            'INSERT INTO customers (id, name, document, status, email, phone)
             VALUES (:id, :name, :document, :status, :email, :phone)'
        );
        $stmt->execute([
            ':id'       => $customer->getId(),
            ':name'     => $customer->getName(),
            ':document' => $customer->getDocument()->getValue(),
            ':status'   => $customer->getStatus()->value,
            ':email'    => $customer->getEmail(),
            ':phone'    => $customer->getPhone(),
        ]);
    }

    public function update(Customer $customer): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE customers
                SET name = :name, status = :status, email = :email, phone = :phone
              WHERE id = :id'
        );
        $stmt->execute([
            ':name'   => $customer->getName(),
            ':status' => $customer->getStatus()->value,
            ':email'  => $customer->getEmail(),
            ':phone'  => $customer->getPhone(),
            ':id'     => $customer->getId(),
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
        // status/email/phone are nullable in the row so the hydration keeps working against
        // a database that has not received migration 002 yet.
        return Customer::create(
            id: (string)$row['id'],
            name: (string)$row['name'],
            document: new Document((string)$row['document']),
            status: CustomerStatus::fromString(isset($row['status']) ? (string)$row['status'] : null),
            email: isset($row['email']) ? (string)$row['email'] : null,
            phone: isset($row['phone']) ? (string)$row['phone'] : null,
        );
    }
}
