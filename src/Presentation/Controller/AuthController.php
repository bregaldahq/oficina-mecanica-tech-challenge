<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Application\DTO\Auth\AuthDTO;
use App\Application\UseCase\Auth\AuthenticateUseCase;
use App\Domain\Exception\DomainException;

class AuthController
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_SECS  = 60;

    public function __construct(
        private readonly AuthenticateUseCase $authenticateUseCase,
    ) {
    }

    public function login(): void
    {
        if (!$this->checkRateLimit()) {
            return;
        }

        $body = $this->parseBody();

        if (empty($body['username']) || empty($body['password'])) {
            http_response_code(400);
            echo json_encode(['error' => 'username e password são obrigatórios.']);
            return;
        }

        try {
            $token = $this->authenticateUseCase->execute(new AuthDTO(
                username: $body['username'],
                password: $body['password'],
            ));

            http_response_code(200);
            echo json_encode(['token' => $token]);
        } catch (DomainException $e) {
            http_response_code(401);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    private function checkRateLimit(): bool
    {
        $ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $window  = (int)floor(time() / self::WINDOW_SECS);
        $key     = 'auth_' . md5($ip) . '_' . $window;
        $tmpFile = sys_get_temp_dir() . '/' . $key;

        $attempts = file_exists($tmpFile) ? (int)file_get_contents($tmpFile) : 0;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $retryAfter = self::WINDOW_SECS - (time() % self::WINDOW_SECS);
            header('Retry-After: ' . $retryAfter);
            http_response_code(429);
            echo json_encode(['error' => 'Muitas tentativas. Tente novamente em ' . $retryAfter . ' segundos.']);
            return false;
        }

        file_put_contents($tmpFile, (string)($attempts + 1));
        return true;
    }

    /** @return array<string, mixed> */
    private function parseBody(): array
    {
        $raw = file_get_contents('php://input');
        return json_decode($raw ?: '{}', true) ?? [];
    }
}
