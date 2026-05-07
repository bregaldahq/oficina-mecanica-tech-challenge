<?php

declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\ValueObject\Document;

class Customer
{
    public function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly Document $document,
    ) {
    }

    public static function create(string $id, string $name, Document $document): self
    {
        return new self($id, $name, $document);
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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'name'     => $this->name,
            'document' => $this->document->getValue(),
        ];
    }
}
