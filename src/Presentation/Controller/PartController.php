<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Domain\Entity\Part;
use App\Domain\Repository\PartRepositoryInterface;
use App\Domain\UuidGeneratorInterface;
use App\Presentation\Request\RequestValidator;

class PartController
{
    public function __construct(
        private readonly PartRepositoryInterface $repository,
        private readonly UuidGeneratorInterface $uuidGenerator,
    ) {
    }

    public function index(): void
    {
        http_response_code(200);
        echo json_encode(array_map(fn($p) => $p->toArray(), $this->repository->findAll()));
    }

    /** @param array<string, string> $params */
    public function show(array $params): void
    {
        $part = $this->repository->findById($params['id']);

        if ($part === null) {
            http_response_code(404);
            echo json_encode(['error' => "Peça não encontrada: {$params['id']}"]);
            return;
        }

        http_response_code(200);
        echo json_encode($part->toArray());
    }

    public function store(): void
    {
        $body  = $this->parseBody();
        $desc  = RequestValidator::requireString($body, 'description');
        $price = RequestValidator::requireFloat($body, 'price');
        $stock = RequestValidator::optionalInt($body, 'stock_quantity', 0);

        $part = Part::create($this->uuidGenerator->generate(), $desc, $price, $stock);
        $this->repository->save($part);

        http_response_code(201);
        echo json_encode($part->toArray());
    }

    /** @param array<string, string> $params */
    public function update(array $params): void
    {
        $part = $this->repository->findById($params['id']);
        if ($part === null) {
            http_response_code(404);
            echo json_encode(['error' => "Peça não encontrada: {$params['id']}"]);
            return;
        }

        $body    = $this->parseBody();
        $desc    = RequestValidator::requireString($body, 'description');
        $price   = RequestValidator::requireFloat($body, 'price');

        $updated = Part::create($part->getId(), $desc, $price, $part->getStockQuantity());
        $this->repository->update($updated);

        http_response_code(200);
        echo json_encode($updated->toArray());
    }

    /** @param array<string, string> $params */
    public function updateStock(array $params): void
    {
        $part = $this->repository->findById($params['id']);
        if ($part === null) {
            http_response_code(404);
            echo json_encode(['error' => "Peça não encontrada: {$params['id']}"]);
            return;
        }

        $body     = $this->parseBody();
        $quantity = RequestValidator::requireInt($body, 'quantity');

        $newPart = Part::create(
            $part->getId(),
            $part->getDescription(),
            $part->getPrice(),
            $part->getStockQuantity() + $quantity,
        );

        $this->repository->update($newPart);

        http_response_code(200);
        echo json_encode($newPart->toArray());
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        $part = $this->repository->findById($params['id']);
        if ($part === null) {
            http_response_code(404);
            echo json_encode(['error' => "Peça não encontrada: {$params['id']}"]);
            return;
        }

        $this->repository->delete($params['id']);
        http_response_code(204);
    }

    /** @return array<string, mixed> */
    private function parseBody(): array
    {
        $raw = file_get_contents('php://input');
        return json_decode($raw ?: '{}', true) ?? [];
    }
}
