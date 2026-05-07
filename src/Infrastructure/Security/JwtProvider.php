<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Exception\DomainException;

class JwtProvider
{
    private readonly string $secret;
    private readonly int $expiration;

    public function __construct()
    {
        $this->secret     = $_ENV['JWT_SECRET'] ?? 'default-secret-change-me';
        $this->expiration = (int)($_ENV['JWT_EXPIRATION'] ?? 3600);
    }

    /** @param array<string, mixed> $payload */
    public function generate(array $payload): string
    {
        $now = time();

        $claims = array_merge($payload, [
            'iss' => 'oficina-mecanica-api',
            'iat' => $now,
            'exp' => $now + $this->expiration,
        ]);

        $headerJson = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $bodyJson   = json_encode($claims);
        assert($headerJson !== false && $bodyJson !== false);
        $header    = $this->base64urlEncode($headerJson);
        $body      = $this->base64urlEncode($bodyJson);
        $signature = $this->base64urlEncode(hash_hmac('sha256', "{$header}.{$body}", $this->secret, true));

        return "{$header}.{$body}.{$signature}";
    }

    /** @return array<string, mixed> */
    public function validate(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new DomainException("Token JWT inválido: formato incorreto.");
        }

        [$header, $body, $signature] = $parts;

        $expectedSignature = $this->base64urlEncode(
            hash_hmac('sha256', "{$header}.{$body}", $this->secret, true)
        );

        if (!hash_equals($expectedSignature, $signature)) {
            throw new DomainException("Token JWT inválido: assinatura incorreta.");
        }

        $payload = json_decode($this->base64urlDecode($body), true);

        if (!isset($payload['exp']) || $payload['exp'] < time()) {
            throw new DomainException("Token JWT expirado.");
        }

        return $payload;
    }

    private function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64urlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
