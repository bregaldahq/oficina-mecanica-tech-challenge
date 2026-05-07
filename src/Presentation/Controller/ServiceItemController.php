<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Domain\Entity\ServiceItem;
use App\Domain\Repository\ServiceItemRepositoryInterface;
use App\Domain\UuidGeneratorInterface;
use App\Presentation\Request\RequestValidator;

class ServiceItemController
{
    public function __construct(
        private readonly ServiceItemRepositoryInterface $repository,
        private readonly UuidGeneratorInterface $uuidGenerator,
    ) {
    }

    public function index(): void
    {
        http_response_code(200);
        echo json_encode(array_map(fn($i) => $i->toArray(), $this->repository->findAll()));
    }

    /** @param array<string, string> $params */
    public function show(array $params): void
    {
        $item = $this->repository->findById($params['id']);

        if ($item === null) {
            http_response_code(404);
            echo json_encode(['error' => "Serviço não encontrado: {$params['id']}"]);
            return;
        }

        http_response_code(200);
        echo json_encode($item->toArray());
    }

    public function store(): void
    {
        $body  = $this->parseBody();
        $desc  = RequestValidator::requireString($body, 'description');
        $price = RequestValidator::requireFloat($body, 'base_price');
        $time  = RequestValidator::optionalInt($body, 'estimated_time_minutes', 0);

        $item = ServiceItem::create($this->uuidGenerator->generate(), $desc, $price, $time);
        $this->repository->save($item);

        http_response_code(201);
        echo json_encode($item->toArray());
    }

    /** @param array<string, string> $params */
    public function update(array $params): void
    {
        $item = $this->repository->findById($params['id']);
        if ($item === null) {
            http_response_code(404);
            echo json_encode(['error' => "Serviço não encontrado: {$params['id']}"]);
            return;
        }

        $body    = $this->parseBody();
        $desc    = RequestValidator::requireString($body, 'description');
        $price   = RequestValidator::requireFloat($body, 'base_price');
        $time    = RequestValidator::optionalInt($body, 'estimated_time_minutes', $item->getEstimatedTimeMinutes());

        $updated = ServiceItem::create($item->getId(), $desc, $price, $time);
        $this->repository->update($updated);

        http_response_code(200);
        echo json_encode($updated->toArray());
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        $item = $this->repository->findById($params['id']);
        if ($item === null) {
            http_response_code(404);
            echo json_encode(['error' => "Serviço não encontrado: {$params['id']}"]);
            return;
        }

        $this->repository->delete($params['id']);
        http_response_code(204);
    }

    public function metrics(): void
    {
        $data = $this->repository->getMetrics();
        http_response_code(200);
        echo json_encode($data);
    }

    /** @return array<string, mixed> */
    private function parseBody(): array
    {
        $raw = file_get_contents('php://input');
        return json_decode($raw ?: '{}', true) ?? [];
    }
}
