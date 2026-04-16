<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCase;

use App\Application\DTO\ServiceOrder\ChangeStatusDTO;
use App\Application\UseCase\ServiceOrder\ChangeServiceOrderStatusUseCase;
use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Entity\Customer;
use App\Domain\Entity\Vehicle;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\InvalidStatusTransitionException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\ServiceOrderRepositoryInterface;
use App\Domain\ValueObject\Document;
use App\Domain\ValueObject\LicensePlate;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ChangeServiceOrderStatusUseCaseTest extends TestCase
{
    private MockObject&ServiceOrderRepositoryInterface $orderRepo;
    private ChangeServiceOrderStatusUseCase $useCase;

    protected function setUp(): void
    {
        $this->orderRepo = $this->createMock(ServiceOrderRepositoryInterface::class);
        $this->useCase   = new ChangeServiceOrderStatusUseCase($this->orderRepo);
    }

    private function makeOrder(): ServiceOrder
    {
        $customer = Customer::create('cust-1', 'João', new Document('529.982.247-25'));
        $vehicle  = Vehicle::create('veh-1', 'cust-1', new LicensePlate('ABC-1234'), 'Toyota', 'Corolla', 2020);
        return ServiceOrder::create('order-1', $customer, $vehicle);
    }

    public function testChangesStatusToDiagnosis(): void
    {
        $order = $this->makeOrder();
        $this->orderRepo->method('findById')->willReturn($order);
        $this->orderRepo->expects($this->once())->method('updateStatus');

        $result = $this->useCase->execute(new ChangeStatusDTO('order-1', 'DIAGNOSIS'));

        $this->assertSame('DIAGNOSIS', $result->getStatus());
    }

    public function testThrowsWhenOrderNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->orderRepo->method('findById')->willReturn(null);

        $this->useCase->execute(new ChangeStatusDTO('not-found', 'DIAGNOSIS'));
    }

    public function testThrowsOnInvalidStatusString(): void
    {
        $this->expectException(DomainException::class);
        $order = $this->makeOrder();
        $this->orderRepo->method('findById')->willReturn($order);

        $this->useCase->execute(new ChangeStatusDTO('order-1', 'INVALID_STATUS'));
    }

    public function testThrowsOnInvalidTransition(): void
    {
        $this->expectException(InvalidStatusTransitionException::class);
        $order = $this->makeOrder();
        $this->orderRepo->method('findById')->willReturn($order);

        $this->useCase->execute(new ChangeStatusDTO('order-1', 'DELIVERING'));
    }

    public function testThrowsWhenSkippingFromReceivedToExecuting(): void
    {
        $this->expectException(InvalidStatusTransitionException::class);
        $order = $this->makeOrder(); // RECEIVED
        $this->orderRepo->method('findById')->willReturn($order);

        $this->useCase->execute(new ChangeStatusDTO('order-1', 'EXECUTING'));
    }

    public function testFullFlowChangesStatus(): void
    {
        $order = $this->makeOrder();
        $this->orderRepo->method('findById')->willReturn($order);
        $this->orderRepo->method('updateStatus');

        $this->useCase->execute(new ChangeStatusDTO('order-1', 'DIAGNOSIS'));
        $this->useCase->execute(new ChangeStatusDTO('order-1', 'AWAITING_APPROVAL'));
        $this->useCase->execute(new ChangeStatusDTO('order-1', 'EXECUTING'));

        $this->assertSame('EXECUTING', $order->getStatus());
    }
}
