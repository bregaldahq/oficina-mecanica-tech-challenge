<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Infrastructure\Database\PdoConnection;

class HealthController
{
    public function check(): void
    {
        $dbStatus = 'connected';

        try {
            PdoConnection::getInstance()->query('SELECT 1');
        } catch (\Throwable) {
            $dbStatus = 'error';
        }

        $status = $dbStatus === 'connected' ? 'ok' : 'degraded';
        http_response_code($status === 'ok' ? 200 : 503);

        echo json_encode([
            'status'    => $status,
            'version'   => '1.0.0',
            'database'  => $dbStatus,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
