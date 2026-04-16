<?php

declare(strict_types=1);

namespace App\Application\UseCase\Auth;

use App\Application\DTO\Auth\AuthDTO;
use App\Domain\Exception\DomainException;
use App\Infrastructure\Security\JwtProvider;

class AuthenticateUseCase
{
    private readonly string $adminUsername;
    private readonly string $adminPassword;

    public function __construct(
        private readonly JwtProvider $jwtProvider,
    ) {
        $this->adminUsername = $_ENV['ADMIN_USERNAME'] ?? 'admin';
        $this->adminPassword = $_ENV['ADMIN_PASSWORD'] ?? 'admin123';
    }

    public function execute(AuthDTO $dto): string
    {
        $validUsername = hash_equals($this->adminUsername, $dto->username);
        $validPassword = hash_equals($this->adminPassword, $dto->password);

        if (!$validUsername || !$validPassword) {
            throw new DomainException("Credenciais inválidas.");
        }

        return $this->jwtProvider->generate(['sub' => $dto->username, 'role' => 'admin']);
    }
}
