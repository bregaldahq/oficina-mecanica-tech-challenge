<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Repository\ServiceOrderStatusHistoryRepositoryInterface;
use App\Infrastructure\Database\PdoConnection;
use PDO;

class PdoServiceOrderStatusHistoryRepository implements ServiceOrderStatusHistoryRepositoryInterface
{
    /** DATETIME(3) — millisecond precision, as defined in migration 002. */
    private const DATETIME_FORMAT = 'Y-m-d H:i:s.v';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? PdoConnection::getInstance();
    }

    public function append(
        string $id,
        string $serviceOrderId,
        ?string $fromStatus,
        string $toStatus,
        \DateTimeImmutable $changedAt,
        ?string $changedBy,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO service_order_status_history
                 (id, service_order_id, from_status, to_status, changed_at, changed_by)
             VALUES (:id, :service_order_id, :from_status, :to_status, :changed_at, :changed_by)'
        );

        $stmt->execute([
            ':id'               => $id,
            ':service_order_id' => $serviceOrderId,
            ':from_status'      => $fromStatus,
            ':to_status'        => $toStatus,
            ':changed_at'       => $changedAt->format(self::DATETIME_FORMAT),
            ':changed_by'       => $changedBy,
        ]);
    }

    public function findLastChangedAtBefore(string $serviceOrderId, \DateTimeImmutable $before): ?\DateTimeImmutable
    {
        $stmt = $this->pdo->prepare(
            'SELECT changed_at
               FROM service_order_status_history
              WHERE service_order_id = :service_order_id
                AND changed_at < :before
              ORDER BY changed_at DESC
              LIMIT 1'
        );
        $stmt->execute([
            ':service_order_id' => $serviceOrderId,
            ':before'           => $before->format(self::DATETIME_FORMAT),
        ]);

        $row = $stmt->fetch();

        if (!is_array($row) || !isset($row['changed_at'])) {
            return null;
        }

        $changedAt = new \DateTimeImmutable((string)$row['changed_at']);

        return $changedAt;
    }
}
