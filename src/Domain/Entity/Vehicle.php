<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Exception\DomainException;
use App\Domain\ValueObject\LicensePlate;

class Vehicle
{
    public function __construct(
        private readonly string $id,
        private readonly string $customerId,
        private readonly LicensePlate $licensePlate,
        private readonly string $brand,
        private readonly string $model,
        private readonly int $year,
    ) {
    }

    public static function create(
        string $id,
        string $customerId,
        LicensePlate $licensePlate,
        string $brand,
        string $model,
        int $year
    ): self {
        $currentYear = (int)date('Y');
        if ($year < 1886 || $year > $currentYear + 1) {
            throw new DomainException(
                "Ano do veículo inválido: {$year}. Deve estar entre 1886 e " . ($currentYear + 1) . "."
            );
        }
        return new self($id, $customerId, $licensePlate, $brand, $model, $year);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCustomerId(): string
    {
        return $this->customerId;
    }

    public function getLicensePlate(): LicensePlate
    {
        return $this->licensePlate;
    }

    public function getBrand(): string
    {
        return $this->brand;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getYear(): int
    {
        return $this->year;
    }

    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'customer_id'   => $this->customerId,
            'license_plate' => $this->licensePlate->getValue(),
            'brand'         => $this->brand,
            'model'         => $this->model,
            'year'          => $this->year,
        ];
    }
}
