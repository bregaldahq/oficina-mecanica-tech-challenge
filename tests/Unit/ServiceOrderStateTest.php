<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Entity\Customer;
use App\Domain\Entity\Part;
use App\Domain\Entity\ServiceItem;
use App\Domain\Entity\Vehicle;
use App\Domain\Exception\InsufficientStockException;
use App\Domain\Exception\InvalidDocumentException;
use App\Domain\Exception\InvalidStatusTransitionException;
use App\Domain\ValueObject\Document;
use App\Domain\ValueObject\LicensePlate;
use PHPUnit\Framework\TestCase;

class ServiceOrderStateTest extends TestCase
{
    private Customer $customer;
    private Vehicle $vehicle;

    protected function setUp(): void
    {
        $this->customer = Customer::create('cust-1', 'João Silva', new Document('529.982.247-25'));
        $this->vehicle  = Vehicle::create('veh-1', 'cust-1', new LicensePlate('ABC-1234'), 'Toyota', 'Corolla', 2020);
    }

    private function makeOrder(): ServiceOrder
    {
        return ServiceOrder::create('order-1', $this->customer, $this->vehicle);
    }

    public function testNewOrderHasReceivedStatus(): void
    {
        $order = $this->makeOrder();
        $this->assertSame('RECEIVED', $order->getStatus());
    }

    public function testValidTransitionReceivedToDiagnosis(): void
    {
        $order = $this->makeOrder();
        $order->changeToDiagnosis();
        $this->assertSame('DIAGNOSIS', $order->getStatus());
    }

    public function testValidFullFlow(): void
    {
        $order = $this->makeOrder();
        $order->changeToDiagnosis();
        $order->sendForApproval();
        $order->approve();
        $order->finish();
        $order->deliver();

        $this->assertSame('DELIVERED', $order->getStatus());
    }

    public function testSkipFromReceivedToExecutingThrows(): void
    {
        $this->expectException(InvalidStatusTransitionException::class);

        $order = $this->makeOrder();
        $order->approve();
    }

    public function testSkipFromReceivedToDeliveredThrows(): void
    {
        $this->expectException(InvalidStatusTransitionException::class);

        $order = $this->makeOrder();
        $order->deliver();
    }

    public function testSkipFromDiagnosisToFinishedThrows(): void
    {
        $this->expectException(InvalidStatusTransitionException::class);

        $order = $this->makeOrder();
        $order->changeToDiagnosis();
        $order->finish();
    }

    public function testCannotTransitionFromDelivered(): void
    {
        $this->expectException(InvalidStatusTransitionException::class);

        $order = $this->makeOrder();
        $order->changeToDiagnosis();
        $order->sendForApproval();
        $order->approve();
        $order->finish();
        $order->deliver();
        $order->changeToDiagnosis();
    }

    public function testCalculateTotalWithTwoServicesAndThreeParts(): void
    {
        $order = $this->makeOrder();

        $order->addService(ServiceItem::create('svc-1', 'Troca de óleo', 150.00, 60));
        $order->addService(ServiceItem::create('svc-2', 'Alinhamento', 80.00, 45));

        $order->addPart(Part::create('p-1', 'Filtro de óleo', 45.00, 10), 2);
        $order->addPart(Part::create('p-2', 'Pastilha de freio', 120.00, 5), 1);
        $order->addPart(Part::create('p-3', 'Vela de ignição', 25.00, 20), 4);

        // 150 + 80 + 90 + 120 + 100 = 540.00
        $this->assertSame(540.00, $order->calculateTotalAmount());
    }

    public function testSendForApprovalStoresTotalAmount(): void
    {
        $order = $this->makeOrder();
        $order->addService(ServiceItem::create('svc-1', 'Revisão', 200.00, 120));
        $order->addPart(Part::create('p-1', 'Filtro ar', 50.00, 5), 1);

        $order->changeToDiagnosis();
        $order->sendForApproval();

        $this->assertSame(250.00, $order->getTotalAmount());
        $this->assertSame('AWAITING_APPROVAL', $order->getStatus());
    }

    public function testInvalidCpfThrowsImmediately(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new Document('111.111.111-11');
    }

    public function testInvalidCpfWrongCheckDigit(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new Document('529.982.247-00');
    }

    public function testInvalidCnpjThrows(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new Document('00.000.000/0000-00');
    }

    public function testValidCpfAccepted(): void
    {
        $doc = new Document('529.982.247-25');
        $this->assertSame('52998224725', $doc->getValue());
    }

    public function testAddPartDecreasesStockInMemory(): void
    {
        $order = $this->makeOrder();
        $part  = Part::create('p-1', 'Filtro', 45.00, 10);

        $order->addPart($part, 3);

        $this->assertSame(7, $part->getStockQuantity());
    }

    public function testAddPartWithInsufficientStockThrows(): void
    {
        $this->expectException(InsufficientStockException::class);

        $order = $this->makeOrder();
        $part  = Part::create('p-1', 'Filtro', 45.00, 2);

        $order->addPart($part, 5);
    }
}
