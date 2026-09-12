<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Repository\PdoServiceOrderStatusHistoryRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/** Queries behind durationSeconds and orderAgeSeconds (CONTRATOS.md §7). */
class PdoServiceOrderStatusHistoryRepositoryTest extends TestCase
{
    private PdoServiceOrderStatusHistoryRepository $repo;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('
            CREATE TABLE service_order_status_history (
                id TEXT PRIMARY KEY,
                service_order_id TEXT NOT NULL,
                from_status TEXT NULL,
                to_status TEXT NOT NULL,
                changed_at TEXT NOT NULL,
                changed_by TEXT NULL
            )
        ');

        $this->repo = new PdoServiceOrderStatusHistoryRepository($pdo);
    }

    private function at(string $when): \DateTimeImmutable
    {
        return new \DateTimeImmutable($when);
    }

    public function testFirstChangedAtIsTheOpeningOfTheOrderRegardlessOfInsertionOrder(): void
    {
        $this->repo->append('h2', 'order-1', 'RECEIVED', 'DIAGNOSIS', $this->at('2026-09-02 10:00:00'), null);
        $this->repo->append('h1', 'order-1', null, 'RECEIVED', $this->at('2026-09-01 08:30:00'), null);
        $this->repo->append('h3', 'order-2', null, 'RECEIVED', $this->at('2026-08-01 00:00:00'), null);

        $this->assertEquals($this->at('2026-09-01 08:30:00'), $this->repo->findFirstChangedAt('order-1'));
    }

    public function testFirstChangedAtIsNullForAnOrderWithoutHistory(): void
    {
        $this->assertNull($this->repo->findFirstChangedAt('desconhecida'));
    }

    public function testLastChangedAtBeforeIgnoresTheCurrentTransition(): void
    {
        $this->repo->append('h1', 'order-1', null, 'RECEIVED', $this->at('2026-09-01 08:00:00'), null);
        $this->repo->append('h2', 'order-1', 'RECEIVED', 'DIAGNOSIS', $this->at('2026-09-01 09:00:00'), null);

        $this->assertEquals(
            $this->at('2026-09-01 08:00:00'),
            $this->repo->findLastChangedAtBefore('order-1', $this->at('2026-09-01 09:00:00'))
        );
    }
}
