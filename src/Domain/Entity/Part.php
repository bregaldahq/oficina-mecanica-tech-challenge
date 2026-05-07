<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Exception\InsufficientStockException;

class Part
{
    public function __construct(
        private readonly string $id,
        private readonly string $description,
        private readonly float $price,
        private int $stockQuantity,
    ) {
    }

    public static function create(string $id, string $description, float $price, int $stockQuantity): self
    {
        return new self($id, $description, $price, $stockQuantity);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function getStockQuantity(): int
    {
        return $this->stockQuantity;
    }

    public function decreaseStock(int $quantity): void
    {
        if ($quantity > $this->stockQuantity) {
            throw new InsufficientStockException(
                "Estoque insuficiente para '{$this->description}'. Disponível: {$this->stockQuantity}, Solicitado: {$quantity}."
            );
        }
        $this->stockQuantity -= $quantity;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'description'    => $this->description,
            'price'          => $this->price,
            'stock_quantity' => $this->stockQuantity,
        ];
    }
}
