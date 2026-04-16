<?php

declare(strict_types=1);

namespace App\Presentation\Middleware;

use App\Infrastructure\Security\JwtProvider;

class AuthMiddleware
{
    public function __construct(
        private readonly JwtProvider $jwtProvider,
    ) {
    }

    public function handle(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!str_starts_with($header, 'Bearer ')) {
            http_response_code(401);
            echo json_encode(['error' => 'Token expirado ou invalido']);
            exit;
        }

        $token = substr($header, 7);

        try {
            return $this->jwtProvider->validate($token);
        } catch (\Throwable) {
            http_response_code(401);
            echo json_encode(['error' => 'Token expirado ou invalido']);
            exit;
        }
    }
}
