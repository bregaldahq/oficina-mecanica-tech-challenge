<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\UuidGeneratorInterface;

class UuidGenerator implements UuidGeneratorInterface
{
    public function generate(): string
    {
        $bytes = random_bytes(16);

        // Set version 4 bits
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        // Set variant bits
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6)),
        );
    }
}
