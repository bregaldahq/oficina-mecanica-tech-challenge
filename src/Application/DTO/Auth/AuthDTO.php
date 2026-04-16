<?php

declare(strict_types=1);

namespace App\Application\DTO\Auth;

class AuthDTO
{
    public function __construct(
        public readonly string $username,
        public readonly string $password,
    ) {
    }
}
