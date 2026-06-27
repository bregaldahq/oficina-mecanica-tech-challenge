<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCase;

use App\Application\DTO\ServiceOrder\ReviewBudgetDTO;
use App\Application\UseCase\ServiceOrder\ReviewBudgetUseCase;
use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Entity\Customer;
use App\Domain\Entity\Vehicle;
use App\Domain\Event\ServiceOrderStatusChangedEvent;
use App\Domain\Exception\InvalidStatusTransitionException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\ServiceOrderRepositoryInterface;
use App\Domain\ValueObject\Document;
use App\Domain\ValueObject\LicensePlate;
use App\Infrastructure\Event\InMemoryEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReviewBudgetUseCaseTest extends TestCase
{
    private MockObject&ServiceOrderRepositoryInterface $orderRepo;
    private InMemoryEventDispatcher $dispatcher;
    private ReviewBudgetUseCase $useCase;

    protected function setUp(): void
    {
        $this->orderRepo  = $this->createMock(ServiceOrderRepositoryInterface::class);
        $this->dispatcher = new InMemoryEventDispatcher();
        $this->useCase    = new ReviewBudgetUseCase($this->orderRepo, $this->dispatcher);
    }

    private function makeAwaitingApprovalOrder(): ServiceOrder
    {
        $customer = Customer::create('cust-1', 'João', new Document('529.982.247-25'));
        $vehicle  = Vehicle::create('veh-1', 'cust-1', new LicensePlate('ABC-1234'), 'Toyota', 'Corolla', 2020);
        $order    = ServiceOrder::create('order-1', $customer, $vehicle);
        $order->changeToDiagnosis();
        $order->sendForApproval();

        return $order;
    }

    public function testApprovedBudgetMovesOrderToExecuting(): void
    {
        $order = $this->makeAwaitingApprovalOrder();
        $this->orderRepo->method('findById')->willReturn($order);
        $this->orderRepo->expects($this->once())->method('updateStatus');

        $result = $this->useCase->execute(new ReviewBudgetDTO('order-1', true));

        $this->assertSame('EXECUTING', $result->getStatus());
    }

    public function testRejectedBudgetMovesOrderToRejected(): void
    {
        $order = $this->makeAwaitingApprovalOrder();
        $this->orderRepo->method('findById')->willReturn($order);
        $this->orderRepo->expects($this->once())->method('updateStatus');

        $result = $this->useCase->execute(new ReviewBudgetDTO('order-1', false));

        $this->assertSame('REJECTED', $result->getStatus());
    }

    public function testThrowsWhenOrderNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->orderRepo->method('findById')->willReturn(null);

        $this->useCase->execute(new ReviewBudgetDTO('missing', true));
    }

    public function testDispatchesStatusChangedEventOnApproval(): void
    {
        $order = $this->makeAwaitingApprovalOrder();
        $this->orderRepo->method('findById')->willReturn($order);
        $this->orderRepo->method('updateStatus');

        /** @var string[] $newStatuses */
        $newStatuses = [];
        $this->dispatcher->subscribe(
            ServiceOrderStatusChangedEvent::class,
            function (ServiceOrderStatusChangedEvent $e) use (&$newStatuses): void {
                $newStatuses[] = $e->newStatus;
            }
        );

        $this->useCase->execute(new ReviewBudgetDTO('order-1', true));

        $this->assertContains('EXECUTING', $newStatuses);
    }

    public function testRejectingNonAwaitingOrderThrows(): void
    {
        $customer = Customer::create('cust-1', 'João', new Document('529.982.247-25'));
        $vehicle  = Vehicle::create('veh-1', 'cust-1', new LicensePlate('ABC-1234'), 'Toyota', 'Corolla', 2020);
        $order    = ServiceOrder::create('order-1', $customer, $vehicle); // RECEIVED
        $this->orderRepo->method('findById')->willReturn($order);

        $this->expectException(InvalidStatusTransitionException::class);
        $this->useCase->execute(new ReviewBudgetDTO('order-1', false));
    }
}
