<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use App\Domain\Exception\InvalidDocumentException;

final class Document
{
    private readonly string $value;

    public function __construct(string $value)
    {
        $digits = preg_replace('/\D/', '', $value);

        if (strlen($digits) === 11) {
            if (!$this->isValidCpf($digits)) {
                throw new InvalidDocumentException("CPF inválido: {$value}");
            }
        } elseif (strlen($digits) === 14) {
            if (!$this->isValidCnpj($digits)) {
                throw new InvalidDocumentException("CNPJ inválido: {$value}");
            }
        } else {
            throw new InvalidDocumentException("Documento inválido: deve ser CPF (11 dígitos) ou CNPJ (14 dígitos).");
        }

        $this->value = $digits;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private function isValidCpf(string $digits): bool
    {
        if (preg_match('/^(\d)\1{10}$/', $digits)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int)$digits[$i] * (10 - $i);
        }
        $remainder   = $sum % 11;
        $firstCheck  = $remainder < 2 ? 0 : 11 - $remainder;

        if ((int)$digits[9] !== $firstCheck) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += (int)$digits[$i] * (11 - $i);
        }
        $remainder   = $sum % 11;
        $secondCheck = $remainder < 2 ? 0 : 11 - $remainder;

        return (int)$digits[10] === $secondCheck;
    }

    private function isValidCnpj(string $digits): bool
    {
        if (preg_match('/^(\d)\1{13}$/', $digits)) {
            return false;
        }

        $weights1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $sum      = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int)$digits[$i] * $weights1[$i];
        }
        $remainder   = $sum % 11;
        $firstCheck  = $remainder < 2 ? 0 : 11 - $remainder;

        if ((int)$digits[12] !== $firstCheck) {
            return false;
        }

        $weights2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $sum      = 0;
        for ($i = 0; $i < 13; $i++) {
            $sum += (int)$digits[$i] * $weights2[$i];
        }
        $remainder   = $sum % 11;
        $secondCheck = $remainder < 2 ? 0 : 11 - $remainder;

        return (int)$digits[13] === $secondCheck;
    }
}
