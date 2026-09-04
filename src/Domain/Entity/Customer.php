<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Exception\DomainException;
use App\Domain\ValueObject\CustomerStatus;
use App\Domain\ValueObject\Document;

class Customer
{
    private function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly Document $document,
        private readonly CustomerStatus $status,
        private readonly ?string $email,
        private readonly ?string $phone,
    ) {
    }

    public static function create(
        string $id,
        string $name,
        Document $document,
        CustomerStatus $status = CustomerStatus::ACTIVE,
        ?string $email = null,
        ?string $phone = null,
    ): self {
        return new self(
            $id,
            $name,
            $document,
            $status,
            self::normalizeEmail($email),
            self::normalizePhone($phone),
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getStatus(): CustomerStatus
    {
        return $this->status;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function isActive(): bool
    {
        return $this->status->canAuthenticate();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'name'     => $this->name,
            'document' => $this->document->getValue(),
            'status'   => $this->status->value,
            'email'    => $this->email,
            'phone'    => $this->phone,
        ];
    }

    private static function normalizeEmail(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        $email = trim($email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('E-mail inválido.');
        }

        return $email;
    }

    private static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (strlen($digits) < 10 || strlen($digits) > 13) {
            throw new DomainException('Telefone inválido. Informe DDD e número.');
        }

        return $digits;
    }
}
