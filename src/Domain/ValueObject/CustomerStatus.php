<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use App\Domain\Exception\DomainException;

/**
 * Lifecycle status of a customer (CONTRATOS.md §6, ajuste 1).
 * Only ACTIVE customers may authenticate through POST /auth/cpf.
 */
enum CustomerStatus: string
{
    case ACTIVE   = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
    case BLOCKED  = 'BLOCKED';

    public static function fromString(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::ACTIVE;
        }

        $status = self::tryFrom(strtoupper($value));

        if ($status === null) {
            throw new DomainException(
                'Status de cliente inválido. Valores aceitos: ACTIVE, INACTIVE, BLOCKED.'
            );
        }

        return $status;
    }

    public function canAuthenticate(): bool
    {
        return $this === self::ACTIVE;
    }
}
