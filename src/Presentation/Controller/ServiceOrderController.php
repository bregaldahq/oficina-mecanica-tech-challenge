<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\DTO\ServiceOrder\AddItemsInputDTO;
use App\Application\DTO\ServiceOrder\ChangeStatusDTO;
use App\Application\DTO\ServiceOrder\CreateServiceOrderInputDTO;
use App\Application\UseCase\ServiceOrder\AddItemsToServiceOrderUseCase;
use App\Application\UseCase\ServiceOrder\ChangeServiceOrderStatusUseCase;
use App\Application\UseCase\ServiceOrder\CreateServiceOrderUseCase;
use App\Application\UseCase\ServiceOrder\GetServiceOrderByClientUseCase;
use App\Application\UseCase\ServiceOrder\GetServiceOrderUseCase;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\InsufficientStockException;
use App\Domain\Exception\InvalidStatusTransitionException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\ServiceOrderRepositoryInterface;

class ServiceOrderController
{
    public function __construct(
        private readonly CreateServiceOrderUseCase $createUseCase,
        private readonly AddItemsToServiceOrderUseCase $addItemsUseCase,
        private readonly GetServiceOrderUseCase $getUseCase,
        private readonly ChangeServiceOrderStatusUseCase $changeStatusUseCase,
        private readonly GetServiceOrderByClientUseCase $getByClientUseCase,
        private readonly ServiceOrderRepositoryInterface $orderRepository,
    ) {
    }

    public function index(): void
    {
        $orders = array_map(fn($o) => $o->toArray(), $this->orderRepository->findAll());
        http_response_code(200);
        echo json_encode($orders);
    }

    public function show(array $params): void
    {
        try {
            $order = $this->getUseCase->execute($params['id']);
            http_response_code(200);
            echo json_encode($order->toArray());
        } catch (NotFoundException $e) {
            http_response_code(404);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function store(): void
    {
        $body = $this->parseBody();

        if (empty($body['customer_id']) || empty($body['vehicle_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'customer_id e vehicle_id são obrigatórios.']);
            return;
        }

        try {
            $order = $this->createUseCase->execute(new CreateServiceOrderInputDTO(
                customerId: $body['customer_id'],
                vehicleId: $body['vehicle_id'],
            ));

            http_response_code(201);
            echo json_encode($order->toArray());
        } catch (NotFoundException $e) {
            http_response_code(404);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (DomainException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function addItems(array $params): void
    {
        $body = $this->parseBody();

        try {
            $order = $this->addItemsUseCase->execute(new AddItemsInputDTO(
                orderId: $params['id'],
                serviceItemIds: $body['service_item_ids'] ?? [],
                parts: $body['parts'] ?? [],
            ));

            http_response_code(200);
            echo json_encode($order->toArray());
        } catch (NotFoundException $e) {
            http_response_code(404);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (InsufficientStockException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (DomainException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function changeStatus(array $params): void
    {
        $body = $this->parseBody();

        if (empty($body['status'])) {
            http_response_code(400);
            echo json_encode(['error' => 'status é obrigatório.']);
            return;
        }

        try {
            $order = $this->changeStatusUseCase->execute(new ChangeStatusDTO(
                orderId: $params['id'],
                newStatus: $body['status'],
            ));

            http_response_code(200);
            echo json_encode($order->toArray());
        } catch (NotFoundException $e) {
            http_response_code(404);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (InvalidStatusTransitionException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (DomainException $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function statusByClient(): void
    {
        $document     = $_GET['document']      ?? '';
        $licensePlate = $_GET['license_plate'] ?? '';
        $status       = $_GET['status']        ?? null;

        if (empty($document) || empty($licensePlate)) {
            http_response_code(400);
            echo json_encode(['error' => 'document e license_plate são obrigatórios.']);
            return;
        }

        try {
            $orders = $this->getByClientUseCase->execute($document, $licensePlate, $status ?: null);
            http_response_code(200);
            echo json_encode($orders);
        } catch (DomainException $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    private function parseBody(): array
    {
        $raw = file_get_contents('php://input');
        return json_decode($raw ?: '{}', true) ?? [];
    }
}
