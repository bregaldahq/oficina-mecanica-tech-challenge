<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ServiceOrder;

use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Entity\Customer;
use App\Domain\Entity\Part;
use App\Domain\Entity\ServiceItem;
use App\Domain\Entity\Vehicle;
use App\Domain\Exception\InvalidStatusTransitionException;
use App\Domain\ValueObject\Document;
use App\Domain\ValueObject\LicensePlate;
use PHPUnit\Framework\TestCase;

class ServiceOrderTest extends TestCase
{
    private Customer $customer;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        $this->customer = Customer::create('cust-001', 'João Silva', new Document('529.982.247-25'));
        $this->vehicle  = Vehicle::create('veh-001', 'cust-001', new LicensePlate('ABC-1234'), 'Toyota', 'Corolla', 2020);
    }

    public function testNewOrderStartsWithReceivedStatus(): void
    {
        $order = ServiceOrder::create('order-001', $this->customer, $this->vehicle);
        $this->assertSame('RECEIVED', $order->getStatus());
    }

    public function testChangeToDiagnosis(): void
    {
        $order = ServiceOrder::create('order-001', $this->customer, $this->vehicle);
        $order->changeToDiagnosis();
        $this->assertSame('DIAGNOSIS', $order->getStatus());
    }

    public function testValidFullFlow(): void
    {
        $order = ServiceOrder::create('order-001', $this->customer, $this->vehicle);
        $order->changeToDiagnosis();
        $order->sendForApproval();
        $order->approve();
        $order->finish();
        $order->deliver();

        $this->assertSame('DELIVERED', $order->getStatus());
    }

    public function testInvalidTransitionThrows(): void
    {
        $this->expectException(InvalidStatusTransitionException::class);
        $order = ServiceOrder::create('order-001', $this->customer, $this->vehicle);
        $order->approve(); // requires AWAITING_APPROVAL
    }

    public function testSendForApprovalCalculatesAndStoresTotal(): void
    {
        $order = ServiceOrder::create('order-001', $this->customer, $this->vehicle);
        $order->addService(ServiceItem::create('svc-1', 'Troca de óleo', 150.00, 60));
        $order->addPart(Part::create('p-1', 'Filtro', 45.00, 10), 2);

        $order->changeToDiagnosis();
        $order->sendForApproval();

        $this->assertSame(240.00, $order->getTotalAmount()); // 150 + 2*45
    }

    public function testCalculatesTotalWithServicesAndParts(): void
    {
        $order = ServiceOrder::create('order-001', $this->customer, $this->vehicle);
        $order->addService(ServiceItem::create('svc-1', 'Troca de óleo', 150.00, 60));
        $order->addPart(Part::create('p-1', 'Filtro', 45.00, 10), 2);

        $this->assertSame(240.00, $order->calculateTotalAmount());
    }

    public function testToArrayContainsExpectedKeys(): void
    {
        $order = ServiceOrder::create('order-001', $this->customer, $this->vehicle);
        $array = $order->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('customer', $array);
        $this->assertArrayHasKey('vehicle', $array);
        $this->assertArrayHasKey('status', $array);
        $this->assertArrayHasKey('total_amount', $array);
    }
}
