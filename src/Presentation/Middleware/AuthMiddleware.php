<?php

declare(strict_types=1);

namespace App\Presentation\Middleware;

use App\Infrastructure\Context\RequestContext;
use App\Infrastructure\Security\JwtProvider;

class AuthMiddleware
{
    public function __construct(
        private readonly JwtProvider $jwtProvider,
        private readonly ?RequestContext $context = null,
    ) {
    }

    /** @return array<string, mixed> */
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
            $claims = $this->jwtProvider->validate($token);
            // Publish the subject so logging and the event subscribers can attribute the request.
            $this->context?->setClaims($claims);

            return $claims;
        } catch (\Throwable) {
            http_response_code(401);
            echo json_encode(['error' => 'Token expirado ou invalido']);
            exit;
        }
    }
}
