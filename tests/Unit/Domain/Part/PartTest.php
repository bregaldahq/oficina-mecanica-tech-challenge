<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Part;

use App\Domain\Entity\Part;
use App\Domain\Exception\InsufficientStockException;
use PHPUnit\Framework\TestCase;

class PartTest extends TestCase
{
    private Part $part;

    protected function setUp(): void
    {
        $this->part = Part::create('part-001', 'Filtro de óleo', 45.90, 10);
    }

    public function testCreatesPartWithCorrectAttributes(): void
    {
        $this->assertSame('part-001', $this->part->getId());
        $this->assertSame('Filtro de óleo', $this->part->getDescription());
        $this->assertSame(45.90, $this->part->getPrice());
        $this->assertSame(10, $this->part->getStockQuantity());
    }

    public function testDecreaseStockSuccessfully(): void
    {
        $this->part->decreaseStock(3);
        $this->assertSame(7, $this->part->getStockQuantity());
    }

    public function testDecreasesEntireStock(): void
    {
        $this->part->decreaseStock(10);
        $this->assertSame(0, $this->part->getStockQuantity());
    }

    public function testDecreaseThrowsWhenInsufficientStock(): void
    {
        $this->expectException(InsufficientStockException::class);
        $this->part->decreaseStock(11);
    }

    public function testToArrayContainsCorrectKeys(): void
    {
        $array = $this->part->toArray();
        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('description', $array);
        $this->assertArrayHasKey('price', $array);
        $this->assertArrayHasKey('stock_quantity', $array);
    }
}
