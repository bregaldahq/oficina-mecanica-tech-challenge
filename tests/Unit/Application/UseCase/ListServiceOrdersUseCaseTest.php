<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCase;

use App\Application\UseCase\ServiceOrder\ListServiceOrdersUseCase;
use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Entity\Customer;
use App\Domain\Entity\Vehicle;
use App\Domain\Repository\ServiceOrderRepositoryInterface;
use App\Domain\ValueObject\Document;
use App\Domain\ValueObject\LicensePlate;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ListServiceOrdersUseCaseTest extends TestCase
{
    private MockObject&ServiceOrderRepositoryInterface $orderRepo;
    private ListServiceOrdersUseCase $useCase;

    protected function setUp(): void
    {
        $this->orderRepo = $this->createMock(ServiceOrderRepositoryInterface::class);
        $this->useCase   = new ListServiceOrdersUseCase($this->orderRepo);
    }

    public function testReturnsActiveOrdersSerializedAsArrays(): void
    {
        $customer = Customer::create('cust-1', 'João', new Document('529.982.247-25'));
        $vehicle  = Vehicle::create('veh-1', 'cust-1', new LicensePlate('ABC-1234'), 'Toyota', 'Corolla', 2020);
        $order    = ServiceOrder::create('order-1', $customer, $vehicle);

        $this->orderRepo->expects($this->once())
            ->method('findActiveOrdered')
            ->willReturn([$order]);

        $result = $this->useCase->execute();

        $this->assertCount(1, $result);
        $this->assertSame('order-1', $result[0]['id']);
        $this->assertSame('RECEIVED', $result[0]['status']);
    }

    public function testReturnsEmptyArrayWhenNoActiveOrders(): void
    {
        $this->orderRepo->expects($this->once())
            ->method('findActiveOrdered')
            ->willReturn([]);

        $this->assertSame([], $this->useCase->execute());
    }
}
