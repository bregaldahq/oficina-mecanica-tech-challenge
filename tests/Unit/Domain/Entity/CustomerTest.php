<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Entity;

use App\Domain\Entity\Customer;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\InvalidDocumentException;
use App\Domain\ValueObject\CustomerStatus;
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
        $this->assertArrayHasKey('status', $array);
        $this->assertArrayHasKey('email', $array);
        $this->assertArrayHasKey('phone', $array);
        $this->assertSame('cust-1', $array['id']);
        $this->assertSame('Maria Oliveira', $array['name']);
    }

    public function testDefaultsToActiveWithoutContactData(): void
    {
        $customer = Customer::create('cust-1', 'Maria Oliveira', new Document('529.982.247-25'));

        $this->assertSame(CustomerStatus::ACTIVE, $customer->getStatus());
        $this->assertTrue($customer->isActive());
        $this->assertNull($customer->getEmail());
        $this->assertNull($customer->getPhone());
    }

    public function testKeepsStatusEmailAndPhone(): void
    {
        $customer = Customer::create(
            'cust-1',
            'Maria Oliveira',
            new Document('529.982.247-25'),
            CustomerStatus::BLOCKED,
            'maria@example.com',
            '(11) 98765-4321',
        );

        $this->assertSame(CustomerStatus::BLOCKED, $customer->getStatus());
        $this->assertFalse($customer->isActive());
        $this->assertSame('maria@example.com', $customer->getEmail());
        // The phone is normalized to digits only.
        $this->assertSame('11987654321', $customer->getPhone());

        $array = $customer->toArray();
        $this->assertSame('BLOCKED', $array['status']);
        $this->assertSame('maria@example.com', $array['email']);
        $this->assertSame('11987654321', $array['phone']);
    }

    public function testBlankContactDataBecomesNull(): void
    {
        $customer = Customer::create('cust-1', 'Maria', new Document('529.982.247-25'), email: '  ', phone: '');

        $this->assertNull($customer->getEmail());
        $this->assertNull($customer->getPhone());
    }

    public function testRejectsInvalidEmail(): void
    {
        $this->expectException(DomainException::class);
        Customer::create('cust-1', 'Maria', new Document('529.982.247-25'), email: 'not-an-email');
    }

    public function testRejectsInvalidPhone(): void
    {
        $this->expectException(DomainException::class);
        Customer::create('cust-1', 'Maria', new Document('529.982.247-25'), phone: '123');
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
