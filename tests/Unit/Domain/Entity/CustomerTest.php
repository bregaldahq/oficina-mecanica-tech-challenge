<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Entity;

use App\Domain\Entity\Customer;
use App\Domain\Exception\InvalidDocumentException;
use App\Domain\ValueObject\Document;
use PHPUnit\Framework\TestCase;

class CustomerTest extends TestCase
{
    public function testCreateWithValidDocument(): void
    {
        $customer = Customer::create('cust-1', 'Maria Oliveira', new Document('529.982.247-25'));

        $this->assertSame('cust-1', $customer->getId());
        $this->assertSame('Maria Oliveira', $customer->getName());
        $this->assertSame('52998224725', $customer->getDocument()->getValue());
    }

    public function testToArrayContainsExpectedKeys(): void
    {
        $customer = Customer::create('cust-1', 'Maria Oliveira', new Document('529.982.247-25'));
        $array    = $customer->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('name', $array);
        $this->assertArrayHasKey('document', $array);
        $this->assertSame('cust-1', $array['id']);
        $this->assertSame('Maria Oliveira', $array['name']);
    }

    public function testInvalidDocumentThrowsException(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new Document('000.000.000-00');
    }

    public function testInvalidDocumentFormatThrowsException(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new Document('not-a-document');
    }
}
