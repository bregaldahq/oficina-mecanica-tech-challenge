<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Entity\ServiceItem;
use App\Domain\Repository\ServiceItemRepositoryInterface;
use App\Infrastructure\Database\PdoConnection;
use PDO;

class PdoServiceItemRepository implements ServiceItemRepositoryInterface
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = PdoConnection::getInstance();
    }

    public function findById(string $id): ?ServiceItem
    {
        $stmt = $this->pdo->prepare('SELECT * FROM service_catalog WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM service_catalog ORDER BY description');
        return array_map([$this, 'hydrate'], $stmt->fetchAll());
    }

    public function save(ServiceItem $serviceItem): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO service_catalog (id, description, base_price, estimated_time_minutes)
             VALUES (:id, :description, :base_price, :estimated_time_minutes)'
        );
        $stmt->execute([
            ':id'                     => $serviceItem->getId(),
            ':description'            => $serviceItem->getDescription(),
            ':base_price'             => $serviceItem->getBasePrice(),
            ':estimated_time_minutes' => $serviceItem->getEstimatedTimeMinutes(),
        ]);
    }

    public function update(ServiceItem $serviceItem): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE service_catalog
             SET description = :description, base_price = :base_price, estimated_time_minutes = :estimated_time_minutes
             WHERE id = :id'
        );
        $stmt->execute([
            ':description'            => $serviceItem->getDescription(),
            ':base_price'             => $serviceItem->getBasePrice(),
            ':estimated_time_minutes' => $serviceItem->getEstimatedTimeMinutes(),
            ':id'                     => $serviceItem->getId(),
        ]);
    }

    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM service_catalog WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * Retorna métricas de execução por tipo de serviço:
     * quantas vezes foi executado, tempo estimado unitário e
     * total acumulado de minutos para os serviços já realizados.
     */
    public function getMetrics(): array
    {
        $stmt = $this->pdo->query(
            'SELECT sc.id AS service_item_id,
                    sc.description,
                    sc.estimated_time_minutes,
                    COUNT(sos.id) AS times_executed,
                    (sc.estimated_time_minutes * COUNT(sos.id)) AS total_estimated_minutes
             FROM service_catalog sc
             LEFT JOIN service_order_services sos ON sos.service_catalog_id = sc.id
             GROUP BY sc.id, sc.description, sc.estimated_time_minutes
             ORDER BY times_executed DESC, sc.description ASC'
        );

        return $stmt->fetchAll();
    }

    private function hydrate(array $row): ServiceItem
    {
        return ServiceItem::create(
            id: $row['id'],
            description: $row['description'],
            basePrice: (float)$row['base_price'],
            estimatedTimeMinutes: (int)$row['estimated_time_minutes'],
        );
    }
}
