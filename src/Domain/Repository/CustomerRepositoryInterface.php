<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Customer;

interface CustomerRepositoryInterface
{
    public function findById(string $id): ?Customer;

    public function findByDocument(string $document): ?Customer;

    /** @return Customer[] */
    public function findAll(): array;

    public function save(Customer $customer): void;

    public function update(Customer $customer): void;

    public function delete(string $id): void;
}
