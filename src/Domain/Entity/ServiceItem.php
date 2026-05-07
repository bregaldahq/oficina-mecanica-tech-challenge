<?php

declare(strict_types=1);

namespace App\Domain\Entity;

class ServiceItem
{
    public function __construct(
        private readonly string $id,
        private readonly string $description,
        private readonly float $basePrice,
        private readonly int $estimatedTimeMinutes,
    ) {
    }

    public static function create(
        string $id,
        string $description,
        float $basePrice,
        int $estimatedTimeMinutes
    ): self {
        return new self($id, $description, $basePrice, $estimatedTimeMinutes);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getBasePrice(): float
    {
        return $this->basePrice;
    }

    public function getEstimatedTimeMinutes(): int
    {
        return $this->estimatedTimeMinutes;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'                     => $this->id,
            'description'            => $this->description,
            'base_price'             => $this->basePrice,
            'estimated_time_minutes' => $this->estimatedTimeMinutes,
        ];
    }
}
