<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\DTO\ServiceOrder\AddItemsInputDTO;
use App\Application\DTO\ServiceOrder\ChangeStatusDTO;
use App\Application\DTO\ServiceOrder\CreateServiceOrderInputDTO;
use App\Application\DTO\ServiceOrder\ReviewBudgetDTO;
use App\Application\UseCase\ServiceOrder\AddItemsToServiceOrderUseCase;
use App\Application\UseCase\ServiceOrder\ChangeServiceOrderStatusUseCase;
use App\Application\UseCase\ServiceOrder\CreateServiceOrderUseCase;
use App\Application\UseCase\ServiceOrder\GetServiceOrderByClientUseCase;
use App\Application\UseCase\ServiceOrder\GetServiceOrderUseCase;
use App\Application\UseCase\ServiceOrder\ListServiceOrdersUseCase;
use App\Application\UseCase\ServiceOrder\ReviewBudgetUseCase;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\InsufficientStockException;
use App\Domain\Exception\InvalidStatusTransitionException;
use App\Domain\Exception\NotFoundException;

class ServiceOrderController
{
    public function __construct(
        private readonly CreateServiceOrderUseCase $createUseCase,
        private readonly AddItemsToServiceOrderUseCase $addItemsUseCase,
        private readonly GetServiceOrderUseCase $getUseCase,
        private readonly ChangeServiceOrderStatusUseCase $changeStatusUseCase,
        private readonly GetServiceOrderByClientUseCase $getByClientUseCase,
        private readonly ListServiceOrdersUseCase $listUseCase,
        private readonly ReviewBudgetUseCase $reviewBudgetUseCase,
    ) {
    }

    public function index(): void
    {
        http_response_code(200);
        echo json_encode($this->listUseCase->execute());
    }

    /** @param array<string, string> $params */
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

    /** @param array<string, string> $params */
    public function addItems(array $params): void
    {
        $body = $this->parseBody();

        try {
            $order = $this->addItemsUseCase->execute(new AddItemsInputDTO(
                orderId: $params['id'],
                serviceItemIds: $body['service_item_ids'] ?? [],
                parts: $body['parts']                     ?? [],
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

    /** @param array<string, string> $params */
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

    /**
     * Receives an external budget decision (approval/rejection) for the customer's order.
     * Intended to be called by an outside approval channel; guarded by a shared webhook token.
     *
     * @param array<string, string> $params
     */
    public function reviewBudget(array $params): void
    {
        if (!$this->webhookAuthorized()) {
            http_response_code(401);
            echo json_encode(['error' => 'Token de webhook inválido.']);
            return;
        }

        $body = $this->parseBody();

        if (!array_key_exists('approved', $body) || !is_bool($body['approved'])) {
            http_response_code(400);
            echo json_encode(['error' => 'O campo "approved" (boolean) é obrigatório.']);
            return;
        }

        try {
            $order = $this->reviewBudgetUseCase->execute(new ReviewBudgetDTO(
                orderId: $params['id'],
                approved: $body['approved'],
            ));

            http_response_code(200);
            echo json_encode($order->toArray());
        } catch (NotFoundException $e) {
            http_response_code(404);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (InvalidStatusTransitionException $e) {
            http_response_code(422);
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

    /** @return array<string, mixed> */
    private function parseBody(): array
    {
        $raw = file_get_contents('php://input');
        return json_decode($raw ?: '{}', true) ?? [];
    }

    /**
     * Validates the shared webhook token when one is configured (via the WEBHOOK_TOKEN secret).
     * When it is not configured (local development), the endpoint stays open.
     */
    private function webhookAuthorized(): bool
    {
        $expected = $_ENV['WEBHOOK_TOKEN'] ?? '';

        if (!is_string($expected) || $expected === '') {
            return true;
        }

        $provided = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '';

        return is_string($provided) && hash_equals($expected, $provided);
    }
}
