<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCase;

use App\Application\DTO\ServiceOrder\AddItemsInputDTO;
use App\Application\UseCase\ServiceOrder\AddItemsToServiceOrderUseCase;
use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Entity\Customer;
use App\Domain\Entity\Part;
use App\Domain\Entity\ServiceItem;
use App\Domain\Entity\Vehicle;
use App\Domain\Exception\InsufficientStockException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\PartRepositoryInterface;
use App\Domain\Repository\ServiceItemRepositoryInterface;
use App\Domain\Repository\ServiceOrderRepositoryInterface;
use App\Domain\ValueObject\Document;
use App\Domain\ValueObject\LicensePlate;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AddItemsToServiceOrderUseCaseTest extends TestCase
{
    private MockObject&ServiceOrderRepositoryInterface $orderRepo;
    private MockObject&ServiceItemRepositoryInterface $serviceItemRepo;
    private MockObject&PartRepositoryInterface $partRepo;
    private AddItemsToServiceOrderUseCase $useCase;

    protected function setUp(): void
    {
        $this->orderRepo       = $this->createMock(ServiceOrderRepositoryInterface::class);
        $this->serviceItemRepo = $this->createMock(ServiceItemRepositoryInterface::class);
        $this->partRepo        = $this->createMock(PartRepositoryInterface::class);

        $this->useCase = new AddItemsToServiceOrderUseCase(
            $this->orderRepo,
            $this->serviceItemRepo,
            $this->partRepo,
        );
    }

    private function makeOrder(): ServiceOrder
    {
        $customer = Customer::create('cust-1', 'João', new Document('529.982.247-25'));
        $vehicle  = Vehicle::create('veh-1', 'cust-1', new LicensePlate('ABC-1234'), 'Toyota', 'Corolla', 2020);
        return ServiceOrder::create('order-1', $customer, $vehicle);
    }

    public function testAddsServicesAndPartsSuccessfully(): void
    {
        $order   = $this->makeOrder();
        $service = ServiceItem::create('svc-1', 'Troca de óleo', 150.00, 60);
        $part    = Part::create('p-1', 'Filtro', 45.00, 10);

        $this->orderRepo->method('findById')->willReturn($order);
        $this->serviceItemRepo->method('findById')->with('svc-1')->willReturn($service);
        $this->partRepo->method('findById')->with('p-1')->willReturn($part);
        $this->orderRepo->expects($this->once())->method('save');

        $result = $this->useCase->execute(new AddItemsInputDTO(
            orderId: 'order-1',
            serviceItemIds: ['svc-1'],
            parts: [['part_id' => 'p-1', 'quantity' => 2]],
        ));

        $this->assertSame('order-1', $result->getId());
        $this->assertCount(1, $result->getServices());
        $this->assertCount(1, $result->getPartsWithQuantity());
    }

    public function testKeepsBudgetCurrentWhenItemsAreAdded(): void
    {
        $order   = $this->makeOrder();
        $service = ServiceItem::create('svc-1', 'Troca de óleo', 150.00, 60);
        $part    = Part::create('p-1', 'Filtro', 45.00, 10);

        $this->orderRepo->method('findById')->willReturn($order);
        $this->serviceItemRepo->method('findById')->with('svc-1')->willReturn($service);
        $this->partRepo->method('findById')->with('p-1')->willReturn($part);

        $result = $this->useCase->execute(new AddItemsInputDTO(
            orderId: 'order-1',
            serviceItemIds: ['svc-1'],
            parts: [['part_id' => 'p-1', 'quantity' => 2]],
        ));

        $this->assertSame(240.00, $result->getTotalAmount());
    }

    public function testThrowsWhenOrderNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->orderRepo->method('findById')->willReturn(null);

        $this->useCase->execute(new AddItemsInputDTO('missing', [], []));
    }

    public function testThrowsWhenServiceItemNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->orderRepo->method('findById')->willReturn($this->makeOrder());
        $this->serviceItemRepo->method('findById')->willReturn(null);

        $this->useCase->execute(new AddItemsInputDTO('order-1', ['nonexistent-svc'], []));
    }

    public function testThrowsWhenPartNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->orderRepo->method('findById')->willReturn($this->makeOrder());
        $this->partRepo->method('findById')->willReturn(null);

        $this->useCase->execute(new AddItemsInputDTO('order-1', [], [['part_id' => 'ghost', 'quantity' => 1]]));
    }

    public function testThrowsWhenInsufficientStock(): void
    {
        $this->expectException(InsufficientStockException::class);

        $order = $this->makeOrder();
        $part  = Part::create('p-1', 'Filtro', 45.00, 1); // only 1 in stock

        $this->orderRepo->method('findById')->willReturn($order);
        $this->partRepo->method('findById')->willReturn($part);

        $this->useCase->execute(new AddItemsInputDTO('order-1', [], [['part_id' => 'p-1', 'quantity' => 5]]));
    }

    public function testSaveIsCalledOnceWithValidData(): void
    {
        $order   = $this->makeOrder();
        $service = ServiceItem::create('svc-1', 'Revisão', 200.00, 120);

        $this->orderRepo->method('findById')->willReturn($order);
        $this->serviceItemRepo->method('findById')->willReturn($service);
        $this->orderRepo->expects($this->once())->method('save')->with($order);

        $this->useCase->execute(new AddItemsInputDTO('order-1', ['svc-1'], []));
    }
}
