<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Security;

use App\Domain\Exception\DomainException;
use App\Infrastructure\Security\JwtProvider;
use PHPUnit\Framework\TestCase;

class JwtProviderTest extends TestCase
{
    private JwtProvider $provider;

    protected function setUp(): void
    {
        $_ENV['JWT_SECRET']     = 'test-secret-key-for-unit-tests';
        $_ENV['JWT_EXPIRATION'] = '3600';

        $this->provider = new JwtProvider();
    }

    public function testGenerateReturnsThreeParts(): void
    {
        $token = $this->provider->generate(['sub' => 'admin']);

        $this->assertStringContainsString('.', $token);
        $this->assertCount(3, explode('.', $token));
    }

    public function testValidateReturnsPayload(): void
    {
        $token   = $this->provider->generate(['sub' => 'admin', 'role' => 'admin']);
        $payload = $this->provider->validate($token);

        $this->assertSame('admin', $payload['sub']);
        $this->assertSame('admin', $payload['role']);
        $this->assertArrayHasKey('exp', $payload);
        $this->assertArrayHasKey('iat', $payload);
    }

    public function testRejectsTokenWithAlteredSignature(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/assinatura/i');

        $token  = $this->provider->generate(['sub' => 'admin']);
        $parts  = explode('.', $token);
        $parts[2] = 'invalidsignature';

        $this->provider->validate(implode('.', $parts));
    }

    public function testRejectsExpiredToken(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/expir/i');

        // Build a token with exp in the past by manipulating the payload part
        $headerJson  = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $payloadJson = json_encode([
            'sub' => 'admin',
            'iss' => 'oficina-mecanica-api',
            'iat' => time() - 7200,
            'exp' => time() - 3600,
        ]);
        assert($headerJson !== false && $payloadJson !== false);
        $header    = $this->base64urlEncode($headerJson);
        $payload   = $this->base64urlEncode($payloadJson);
        $secret    = 'test-secret-key-for-unit-tests';
        $signature = $this->base64urlEncode(hash_hmac('sha256', "{$header}.{$payload}", $secret, true));

        $this->provider->validate("{$header}.{$payload}.{$signature}");
    }

    public function testRejectsMalformedToken(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/formato/i');

        $this->provider->validate('not.a.valid.jwt.token.with.too.many.parts');
    }

    public function testRejectsTokenWithOnlyOnePart(): void
    {
        $this->expectException(DomainException::class);

        $this->provider->validate('justonepart');
    }

    private function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
