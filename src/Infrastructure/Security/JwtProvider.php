<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Exception\DomainException;

/**
 * HS256 JWT provider.
 *
 * The secret, the expiration and the clock are injected so the class can be exercised
 * deterministically — this is what makes the token contract test (see
 * tests/Unit/Infrastructure/Security/JwtContractTest.php) possible. The composition root
 * keeps using self::fromEnv().
 *
 * The claim assembly order (payload first, then iss/iat/exp) is part of the cross-repository
 * contract with the auth Lambda (docs/fase-3/CONTRATOS.md §4) — do not change it.
 */
class JwtProvider
{
    public const ISSUER = 'oficina-mecanica-api';

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /** @param (\Closure(): int)|null $clock returns the current unix timestamp */
    public function __construct(
        private readonly string $secret,
        private readonly int $expiration = 3600,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public static function fromEnv(): self
    {
        $secret = $_ENV['JWT_SECRET'] ?? 'default-secret-change-me';
        assert(is_string($secret));

        return new self($secret, (int)($_ENV['JWT_EXPIRATION'] ?? 3600));
    }

    public function getExpiration(): int
    {
        return $this->expiration;
    }

    /** @param array<string, mixed> $payload */
    public function generate(array $payload): string
    {
        $now = ($this->clock)();

        $claims = array_merge($payload, [
            'iss' => self::ISSUER,
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

        if (!is_array($payload)) {
            throw new DomainException("Token JWT inválido: payload incorreto.");
        }

        if (!isset($payload['exp']) || $payload['exp'] < ($this->clock)()) {
            throw new DomainException("Token JWT expirado.");
        }

        /** @var array<string, mixed> $payload */
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
