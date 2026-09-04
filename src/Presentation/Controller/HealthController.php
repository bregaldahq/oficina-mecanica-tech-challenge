<?php

declare(strict_types=1);

namespace App\Presentation\Controller;

use App\Infrastructure\Database\PdoConnection;

/**
 * Liveness and readiness are deliberately separate endpoints (CONTRATOS.md §5).
 *
 * live() never touches the database: a liveness probe wired to a database check turns a
 * transient RDS blip into a rolling restart of every Pod. Database health belongs to
 * readiness, which only removes the Pod from the load balancer.
 */
class HealthController
{
    private const VERSION = '1.0.0';

    /** GET /api/health — liveness: the PHP process is up and can answer. */
    public function live(): void
    {
        http_response_code(200);

        echo json_encode([
            'status'    => 'ok',
            'version'   => self::VERSION,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }

    /** GET /api/ready — readiness: dependencies (database) are reachable. */
    public function ready(): void
    {
        $dbStatus = 'connected';

        try {
            PdoConnection::getInstance()->query('SELECT 1');
        } catch (\Throwable) {
            $dbStatus = 'error';
        }

        $ready = $dbStatus === 'connected';
        http_response_code($ready ? 200 : 503);

        echo json_encode([
            'status'    => $ready ? 'ready' : 'not_ready',
            'version'   => self::VERSION,
            'database'  => $dbStatus,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
