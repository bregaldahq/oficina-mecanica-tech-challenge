<?php

declare(strict_types=1);

namespace App\Presentation\Request;

class RequestValidator
{
    public static function requireString(array $body, string $field, int $maxLen = 255): string
    {
        if (!isset($body[$field]) || !is_string($body[$field]) || trim($body[$field]) === '') {
            self::abort(400, "Campo obrigatório ausente ou inválido: {$field}");
        }
        $value = trim($body[$field]);
        if (mb_strlen($value) > $maxLen) {
            self::abort(400, "Campo '{$field}' excede o tamanho máximo de {$maxLen} caracteres.");
        }
        return $value;
    }

    public static function requireFloat(array $body, string $field): float
    {
        if (!isset($body[$field]) || !is_numeric($body[$field])) {
            self::abort(400, "Campo obrigatório ausente ou inválido: {$field}");
        }
        return (float)$body[$field];
    }

    public static function requireInt(array $body, string $field): int
    {
        if (!isset($body[$field]) || !is_numeric($body[$field])) {
            self::abort(400, "Campo obrigatório ausente ou inválido: {$field}");
        }
        return (int)$body[$field];
    }

    public static function optionalString(array $body, string $field, ?string $default = null): ?string
    {
        if (!isset($body[$field]) || !is_string($body[$field]) || trim($body[$field]) === '') {
            return $default;
        }
        return trim($body[$field]);
    }

    public static function optionalInt(array $body, string $field, int $default = 0): int
    {
        if (!isset($body[$field]) || !is_numeric($body[$field])) {
            return $default;
        }
        return (int)$body[$field];
    }

    private static function abort(int $code, string $message): never
    {
        http_response_code($code);
        echo json_encode(['error' => $message]);
        exit;
    }
}
