<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCase;

use App\Application\DTO\ServiceOrder\CreateServiceOrderInputDTO;
use App\Application\UseCase\ServiceOrder\CreateServiceOrderUseCase;
use App\Domain\Entity\Customer;
use App\Domain\Event\EventDispatcherInterface;
use App\Domain\Event\ServiceOrderCreatedEvent;
use App\Domain\Entity\Vehicle;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\CustomerRepositoryInterface;
use App\Domain\Repository\ServiceOrderRepositoryInterface;
use App\Domain\Repository\VehicleRepositoryInterface;
use App\Domain\UuidGeneratorInterface;
use App\Domain\ValueObject\Document;
use App\Domain\ValueObject\LicensePlate;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CreateServiceOrderUseCaseTest extends TestCase
{
    private MockObject&CustomerRepositoryInterface $customerRepo;
    private MockObject&VehicleRepositoryInterface $vehicleRepo;
    private MockObject&ServiceOrderRepositoryInterface $orderRepo;
    private MockObject&UuidGeneratorInterface $uuid;
    private MockObject&EventDispatcherInterface $dispatcher;

    private CreateServiceOrderUseCase $useCase;

    protected function setUp(): void
    {
        $this->customerRepo = $this->createMock(CustomerRepositoryInterface::class);
        $this->vehicleRepo  = $this->createMock(VehicleRepositoryInterface::class);
        $this->orderRepo    = $this->createMock(ServiceOrderRepositoryInterface::class);
        $this->uuid         = $this->createMock(UuidGeneratorInterface::class);
        $this->dispatcher   = $this->createMock(EventDispatcherInterface::class);

        $this->uuid->method('generate')->willReturn('test-uuid-1234');

        $this->useCase = new CreateServiceOrderUseCase(
            $this->customerRepo,
            $this->vehicleRepo,
            $this->orderRepo,
            $this->uuid,
            $this->dispatcher,
        );
    }

    private function makeCustomer(): Customer
    {
        return Customer::create('cust-001', 'João Silva', new Document('529.982.247-25'));
    }

    private function makeVehicle(string $customerId = 'cust-001'): Vehicle
    {
        return Vehicle::create('veh-001', $customerId, new LicensePlate('ABC-1234'), 'Toyota', 'Corolla', 2020);
    }

    public function testCreatesOrderSuccessfully(): void
    {
        $this->customerRepo->method('findById')->with('cust-001')->willReturn($this->makeCustomer());
        $this->vehicleRepo->method('findById')->with('veh-001')->willReturn($this->makeVehicle());
        $this->orderRepo->expects($this->once())->method('save');

        $order = $this->useCase->execute(new CreateServiceOrderInputDTO('cust-001', 'veh-001'));

        $this->assertSame('RECEIVED', $order->getStatus());
        $this->assertSame('cust-001', $order->getCustomer()->getId());
        $this->assertSame('test-uuid-1234', $order->getId());
    }

    public function testThrowsWhenCustomerNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->customerRepo->method('findById')->willReturn(null);

        $this->useCase->execute(new CreateServiceOrderInputDTO('invalid', 'veh-001'));
    }

    public function testThrowsWhenVehicleNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->customerRepo->method('findById')->willReturn($this->makeCustomer());
        $this->vehicleRepo->method('findById')->willReturn(null);

        $this->useCase->execute(new CreateServiceOrderInputDTO('cust-001', 'invalid'));
    }

    public function testThrowsWhenVehicleDoesNotBelongToCustomer(): void
    {
        $this->expectException(DomainException::class);
        $this->customerRepo->method('findById')->willReturn($this->makeCustomer());
        $this->vehicleRepo->method('findById')->willReturn($this->makeVehicle(customerId: 'other-customer'));

        $this->useCase->execute(new CreateServiceOrderInputDTO('cust-001', 'veh-001'));
    }

    /**
     * O evento de criacao precisa ser PUBLICADO, nao apenas registrado no agregado.
     *
     * Sem o dispatch ele fica preso ali: nao vira custom event no New Relic e o
     * StatusHistorySubscriber nao grava a linha inicial do historico. Os paineis de
     * volume diario, OS abertas e clientes atendidos ficam zerados — e nada falha,
     * porque a OS e' criada normalmente. Foi assim que passou despercebido.
     */
    public function testPublicaOEventoDeCriacao(): void
    {
        $this->customerRepo->method('findById')->willReturn($this->makeCustomer());
        $this->vehicleRepo->method('findById')->willReturn($this->makeVehicle());

        $publicados = [];
        $this->dispatcher->expects(self::once())
            ->method('dispatchAll')
            ->willReturnCallback(function (array $eventos) use (&$publicados): void {
                $publicados = $eventos;
            });

        $this->useCase->execute(new CreateServiceOrderInputDTO(
            customerId: 'cust-001',
            vehicleId: 'veh-001',
        ));

        self::assertCount(1, $publicados);
        self::assertInstanceOf(ServiceOrderCreatedEvent::class, $publicados[0]);
        self::assertSame('test-uuid-1234', $publicados[0]->orderId);
        self::assertSame('cust-001', $publicados[0]->customerId);
    }
}
