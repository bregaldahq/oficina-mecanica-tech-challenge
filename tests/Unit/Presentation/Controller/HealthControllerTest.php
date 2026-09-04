<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Controller;

use App\Infrastructure\Database\PdoConnection;
use App\Presentation\Controller\HealthController;
use PDO;
use PHPUnit\Framework\TestCase;

/** Liveness x readiness split (CONTRATOS.md §5, item D13). */
class HealthControllerTest extends TestCase
{
    private HealthController $controller;

    protected function setUp(): void
    {
        $this->controller = new HealthController();
        PdoConnection::reset();
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        PdoConnection::reset();
    }

    /** @return array<string, mixed> */
    private function call(string $method): array
    {
        ob_start();
        $this->controller->$method();
        $decoded = json_decode((string)ob_get_clean(), true);

        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testLivenessAnswersOkWithoutTouchingTheDatabase(): void
    {
        // No PDO instance is configured: if live() queried the database it would blow up here.
        $body = $this->call('live');

        $this->assertSame(200, http_response_code());
        $this->assertSame('ok', $body['status']);
        $this->assertArrayNotHasKey('database', $body, 'liveness não deve consultar o banco');
        $this->assertArrayHasKey('version', $body);
        $this->assertArrayHasKey('timestamp', $body);
    }

    public function testReadinessReportsConnectedWhenTheDatabaseAnswers(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        PdoConnection::setInstance($pdo);

        $body = $this->call('ready');

        $this->assertSame(200, http_response_code());
        $this->assertSame('ready', $body['status']);
        $this->assertSame('connected', $body['database']);
    }

    public function testReadinessReturns503WhenTheDatabaseIsUnreachable(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // A closed/broken statement source: querying a dropped in-memory schema still works,
        // so we simulate the outage with a PDO whose queries are rejected.
        PdoConnection::setInstance(new class ('sqlite::memory:') extends PDO {
            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                throw new \PDOException('conexão recusada');
            }
        });

        $body = $this->call('ready');

        $this->assertSame(503, http_response_code());
        $this->assertSame('not_ready', $body['status']);
        $this->assertSame('error', $body['database']);
    }
}
