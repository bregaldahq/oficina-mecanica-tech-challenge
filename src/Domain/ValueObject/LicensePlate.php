<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use App\Domain\Exception\InvalidLicensePlateException;

final class LicensePlate
{
    private readonly string $value;

    // Old format: ABC-1234 or ABC1234
    private const OLD_FORMAT = '/^[A-Z]{3}-?\d{4}$/';
    // Mercosul format: ABC1D23
    private const MERCOSUL_FORMAT = '/^[A-Z]{3}\d[A-Z]\d{2}$/';

    public function __construct(string $value)
    {
        $normalized = strtoupper(trim($value));

        if (!preg_match(self::OLD_FORMAT, $normalized) && !preg_match(self::MERCOSUL_FORMAT, $normalized)) {
            throw new InvalidLicensePlateException("Placa inválida: {$value}. Use o formato ABC-1234 ou ABC1D23.");
        }

        $this->value = preg_replace('/[^A-Z0-9]/', '', $normalized);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getFormatted(): string
    {
        if (preg_match('/^\d{4}$/', substr($this->value, 3))) {
            return substr($this->value, 0, 3) . '-' . substr($this->value, 3);
        }
        return $this->value;
    }

    public function isMercosul(): bool
    {
        return preg_match('/^[A-Z]{3}\d[A-Z]\d{2}$/', $this->value) === 1;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
