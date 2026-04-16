<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Part;

interface PartRepositoryInterface
{
    public function findById(string $id): ?Part;

    /** @return Part[] */
    public function findAll(): array;

    public function save(Part $part): void;

    public function update(Part $part): void;

    public function delete(string $id): void;
}
