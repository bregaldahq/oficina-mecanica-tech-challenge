<?php

declare(strict_types=1);

namespace App\Domain\Aggregate;

use App\Domain\Entity\Customer;
use App\Domain\Entity\Part;
use App\Domain\Entity\ServiceItem;
use App\Domain\Entity\Vehicle;
use App\Domain\Event\DomainEventInterface;
use App\Domain\Event\ServiceOrderCreatedEvent;
use App\Domain\Event\ServiceOrderStatusChangedEvent;
use App\Domain\Exception\InvalidStatusTransitionException;

class ServiceOrder
{
    private const STATUS_RECEIVED          = 'RECEIVED';
    private const STATUS_DIAGNOSIS         = 'DIAGNOSIS';
    private const STATUS_AWAITING_APPROVAL = 'AWAITING_APPROVAL';
    private const STATUS_EXECUTING         = 'EXECUTING';
    private const STATUS_REJECTED          = 'REJECTED';
    private const STATUS_FINISHED          = 'FINISHED';
    private const STATUS_DELIVERED         = 'DELIVERED';

    /** @var ServiceItem[] */
    private array $services = [];

    /** @var array<array{part: Part, quantity: int}> */
    private array $partsWithQuantity = [];

    private float $totalAmount = 0.00;

    /** @var DomainEventInterface[] */
    private array $domainEvents = [];

    private bool $isNew = false;

    private function __construct(
        private readonly string $id,
        private readonly Customer $customer,
        private readonly Vehicle $vehicle,
        private string $status,
        private readonly \DateTimeImmutable $createdAt,
    ) {
    }

    public static function create(string $id, Customer $customer, Vehicle $vehicle): self
    {
        $order        = new self($id, $customer, $vehicle, self::STATUS_RECEIVED, new \DateTimeImmutable());
        $order->isNew = true;
        $order->recordEvent(new ServiceOrderCreatedEvent($id, $customer->getId(), $vehicle->getId()));
        return $order;
    }

    /**
     * Reconstitui a OS a partir de dados persistidos.
     * Usado exclusivamente pela camada de infraestrutura — não dispara eventos de domínio.
     *
     * @param ServiceItem[]                           $services
     * @param array<array{part: Part, quantity: int}> $partsWithQuantity
     */
    public static function reconstitute(
        string $id,
        Customer $customer,
        Vehicle $vehicle,
        string $status,
        float $totalAmount,
        \DateTimeImmutable $createdAt,
        array $services = [],
        array $partsWithQuantity = [],
    ): self {
        $order                    = new self($id, $customer, $vehicle, $status, $createdAt);
        $order->totalAmount       = $totalAmount;
        $order->services          = $services;
        $order->partsWithQuantity = $partsWithQuantity;
        return $order;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getVehicle(): Vehicle
    {
        return $this->vehicle;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getTotalAmount(): float
    {
        return $this->totalAmount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isNew(): bool
    {
        return $this->isNew;
    }

    /** @return ServiceItem[] */
    public function getServices(): array
    {
        return $this->services;
    }

    /** @return array<array{part: Part, quantity: int}> */
    public function getPartsWithQuantity(): array
    {
        return $this->partsWithQuantity;
    }

    /** @return DomainEventInterface[] */
    public function releaseEvents(): array
    {
        $events             = $this->domainEvents;
        $this->domainEvents = [];
        return $events;
    }

    public function addService(ServiceItem $service): void
    {
        $this->services[]  = $service;
        $this->totalAmount = $this->calculateTotalAmount();
    }

    /**
     * Adiciona uma peça à OS e decrementa o estoque em memória.
     * O decremento no banco ocorre dentro da transação do repositório.
     */
    public function addPart(Part $part, int $quantity): void
    {
        $part->decreaseStock($quantity);
        $this->partsWithQuantity[] = ['part' => $part, 'quantity' => $quantity];
        $this->totalAmount         = $this->calculateTotalAmount();
    }

    public function calculateTotalAmount(): float
    {
        $servicesTotal = array_sum(array_map(fn (ServiceItem $s) => $s->getBasePrice(), $this->services));

        $partsTotal = array_sum(
            array_map(fn (array $p) => $p['part']->getPrice() * $p['quantity'], $this->partsWithQuantity)
        );

        return round($servicesTotal + $partsTotal, 2);
    }

    public function changeToDiagnosis(): void
    {
        $this->requireStatus(self::STATUS_RECEIVED, self::STATUS_DIAGNOSIS);
        $this->status = self::STATUS_DIAGNOSIS;
        $this->recordEvent(new ServiceOrderStatusChangedEvent($this->id, self::STATUS_RECEIVED, self::STATUS_DIAGNOSIS));
    }

    public function sendForApproval(): void
    {
        $this->requireStatus(self::STATUS_DIAGNOSIS, self::STATUS_AWAITING_APPROVAL);
        $this->totalAmount = $this->calculateTotalAmount();
        $this->status      = self::STATUS_AWAITING_APPROVAL;
        $this->recordEvent(new ServiceOrderStatusChangedEvent($this->id, self::STATUS_DIAGNOSIS, self::STATUS_AWAITING_APPROVAL));
    }

    public function approve(): void
    {
        $this->requireStatus(self::STATUS_AWAITING_APPROVAL, self::STATUS_EXECUTING);
        $this->status = self::STATUS_EXECUTING;
        $this->recordEvent(new ServiceOrderStatusChangedEvent($this->id, self::STATUS_AWAITING_APPROVAL, self::STATUS_EXECUTING));
    }

    public function reject(): void
    {
        $this->requireStatus(self::STATUS_AWAITING_APPROVAL, self::STATUS_REJECTED);
        $this->status = self::STATUS_REJECTED;
        $this->recordEvent(new ServiceOrderStatusChangedEvent($this->id, self::STATUS_AWAITING_APPROVAL, self::STATUS_REJECTED));
    }

    public function finish(): void
    {
        $this->requireStatus(self::STATUS_EXECUTING, self::STATUS_FINISHED);
        $this->status = self::STATUS_FINISHED;
        $this->recordEvent(new ServiceOrderStatusChangedEvent($this->id, self::STATUS_EXECUTING, self::STATUS_FINISHED));
    }

    public function deliver(): void
    {
        $this->requireStatus(self::STATUS_FINISHED, self::STATUS_DELIVERED);
        $this->status = self::STATUS_DELIVERED;
        $this->recordEvent(new ServiceOrderStatusChangedEvent($this->id, self::STATUS_FINISHED, self::STATUS_DELIVERED));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'customer' => $this->customer->toArray(),
            'vehicle'  => $this->vehicle->toArray(),
            'status'   => $this->status,
            'services' => array_map(fn (ServiceItem $s) => $s->toArray(), $this->services),
            'parts'    => array_map(fn (array $p) => array_merge(
                $p['part']->toArray(),
                ['quantity' => $p['quantity']]
            ), $this->partsWithQuantity),
            'total_amount' => $this->totalAmount,
            'created_at'   => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }

    private function requireStatus(string $required, string $target): void
    {
        if ($this->status !== $required) {
            throw new InvalidStatusTransitionException(
                "Transição inválida de '{$this->status}' para '{$target}'. Status requerido: '{$required}'."
            );
        }
    }

    private function recordEvent(DomainEventInterface $event): void
    {
        $this->domainEvents[] = $event;
    }
}
