<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Domain\Entity\Customer;
use App\Domain\Exception\DomainException;
use App\Domain\Repository\CustomerRepositoryInterface;
use App\Domain\UuidGeneratorInterface;
use App\Domain\ValueObject\Document;
use App\Presentation\Request\RequestValidator;

class CustomerController
{
    public function __construct(
        private readonly CustomerRepositoryInterface $repository,
        private readonly UuidGeneratorInterface $uuidGenerator,
    ) {
    }

    public function index(): void
    {
        http_response_code(200);
        echo json_encode(array_map(fn ($c) => $c->toArray(), $this->repository->findAll()));
    }

    /** @param array<string, string> $params */
    public function show(array $params): void
    {
        $customer = $this->repository->findById($params['id']);

        if ($customer === null) {
            http_response_code(404);
            echo json_encode(['error' => "Cliente não encontrado: {$params['id']}"]);
            return;
        }

        http_response_code(200);
        echo json_encode($customer->toArray());
    }

    public function store(): void
    {
        $body = $this->parseBody();

        try {
            $name     = RequestValidator::requireString($body, 'name');
            $document = new Document(RequestValidator::requireString($body, 'document'));

            $existing = $this->repository->findByDocument($document->getValue());
            if ($existing !== null) {
                http_response_code(422);
                echo json_encode(['error' => 'Já existe um cliente com este documento.']);
                return;
            }

            $customer = Customer::create($this->uuidGenerator->generate(), $name, $document);
            $this->repository->save($customer);

            http_response_code(201);
            echo json_encode($customer->toArray());
        } catch (DomainException $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    /** @param array<string, string> $params */
    public function update(array $params): void
    {
        $customer = $this->repository->findById($params['id']);
        if ($customer === null) {
            http_response_code(404);
            echo json_encode(['error' => "Cliente não encontrado: {$params['id']}"]);
            return;
        }

        $body    = $this->parseBody();
        $newName = RequestValidator::requireString($body, 'name');

        $updated = Customer::create($customer->getId(), $newName, $customer->getDocument());
        $this->repository->update($updated);

        http_response_code(200);
        echo json_encode($updated->toArray());
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        $customer = $this->repository->findById($params['id']);
        if ($customer === null) {
            http_response_code(404);
            echo json_encode(['error' => "Cliente não encontrado: {$params['id']}"]);
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
