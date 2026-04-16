<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCase;

use App\Application\DTO\Auth\AuthDTO;
use App\Application\UseCase\Auth\AuthenticateUseCase;
use App\Domain\Exception\DomainException;
use App\Infrastructure\Security\JwtProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AuthenticateUseCaseTest extends TestCase
{
    private MockObject&JwtProvider $jwtProvider;
    private AuthenticateUseCase $useCase;

    protected function setUp(): void
    {
        $this->jwtProvider = $this->createMock(JwtProvider::class);

        $_ENV['ADMIN_USERNAME'] = 'admin';
        $_ENV['ADMIN_PASSWORD'] = 'admin123';

        $this->useCase = new AuthenticateUseCase($this->jwtProvider);
    }

    public function testReturnsTokenForValidCredentials(): void
    {
        $this->jwtProvider
            ->expects($this->once())
            ->method('generate')
            ->willReturn('mocked.jwt.token');

        $result = $this->useCase->execute(new AuthDTO('admin', 'admin123'));

        $this->assertSame('mocked.jwt.token', $result);
    }

    public function testThrowsForInvalidUsername(): void
    {
        $this->expectException(DomainException::class);
        $this->jwtProvider->expects($this->never())->method('generate');

        $this->useCase->execute(new AuthDTO('wrong_user', 'admin123'));
    }

    public function testThrowsForInvalidPassword(): void
    {
        $this->expectException(DomainException::class);
        $this->jwtProvider->expects($this->never())->method('generate');

        $this->useCase->execute(new AuthDTO('admin', 'wrong_password'));
    }

    public function testThrowsForEmptyCredentials(): void
    {
        $this->expectException(DomainException::class);
        $this->useCase->execute(new AuthDTO('', ''));
    }
}
