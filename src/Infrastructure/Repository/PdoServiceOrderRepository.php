<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Entity\Customer;
use App\Domain\Entity\Part;
use App\Domain\Entity\ServiceItem;
use App\Domain\Entity\Vehicle;
use App\Domain\Repository\ServiceOrderRepositoryInterface;
use App\Domain\UuidGeneratorInterface;
use App\Domain\ValueObject\Document;
use App\Domain\ValueObject\LicensePlate;
use App\Infrastructure\Database\PdoConnection;
use PDO;

class PdoServiceOrderRepository implements ServiceOrderRepositoryInterface
{
    private PDO $pdo;

    public function __construct(private readonly UuidGeneratorInterface $uuidGenerator)
    {
        $this->pdo = PdoConnection::getInstance();
    }

    public function findById(string $id): ?ServiceOrder
    {
        $stmt = $this->pdo->prepare(
            'SELECT so.*,
                    c.name AS c_name, c.document AS c_document,
                    v.customer_id AS v_customer_id, v.license_plate AS v_plate,
                    v.brand AS v_brand, v.model AS v_model, v.year AS v_year
             FROM service_orders so
             JOIN customers c ON c.id = so.customer_id
             JOIN vehicles  v ON v.id = so.vehicle_id
             WHERE so.id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->hydrateWithItems($row);
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT so.*,
                    c.name AS c_name, c.document AS c_document,
                    v.customer_id AS v_customer_id, v.license_plate AS v_plate,
                    v.brand AS v_brand, v.model AS v_model, v.year AS v_year
             FROM service_orders so
             JOIN customers c ON c.id = so.customer_id
             JOIN vehicles  v ON v.id = so.vehicle_id
             ORDER BY so.created_at DESC'
        );
        assert($stmt !== false);
        return array_map(fn(array $row) => $this->hydrateWithItems($row), $stmt->fetchAll());
    }

    public function findByDocumentAndLicensePlate(string $document, string $licensePlate, ?string $status = null): array
    {
        $sql = 'SELECT so.*,
                       c.name AS c_name, c.document AS c_document,
                       v.customer_id AS v_customer_id, v.license_plate AS v_plate,
                       v.brand AS v_brand, v.model AS v_model, v.year AS v_year
                FROM service_orders so
                JOIN customers c ON c.id = so.customer_id
                JOIN vehicles  v ON v.id = so.vehicle_id
                WHERE c.document = :document AND v.license_plate = :plate';

        $params = [':document' => $document, ':plate' => $licensePlate];

        if ($status !== null) {
            $sql .= ' AND so.status = :status';
            $params[':status'] = $status;
        }

        $sql .= ' ORDER BY so.created_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(fn(array $row) => $this->hydrateWithItems($row), $stmt->fetchAll());
    }

    public function save(ServiceOrder $order): void
    {
        $this->pdo->beginTransaction();

        try {
            if ($order->isNew()) {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO service_orders (id, customer_id, vehicle_id, status, total_amount)
                     VALUES (:id, :customer_id, :vehicle_id, :status, :total_amount)'
                );
                $stmt->execute([
                    ':id'           => $order->getId(),
                    ':customer_id'  => $order->getCustomer()->getId(),
                    ':vehicle_id'   => $order->getVehicle()->getId(),
                    ':status'       => $order->getStatus(),
                    ':total_amount' => $order->getTotalAmount(),
                ]);
            } else {
                $exists = $this->pdo->prepare('SELECT id FROM service_orders WHERE id = :id');
                $exists->execute([':id' => $order->getId()]);

                if ($exists->fetch()) {
                    $stmt = $this->pdo->prepare(
                        'UPDATE service_orders
                         SET status = :status, total_amount = :total_amount
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        ':status'       => $order->getStatus(),
                        ':total_amount' => $order->getTotalAmount(),
                        ':id'           => $order->getId(),
                    ]);
                } else {
                    $stmt = $this->pdo->prepare(
                        'INSERT INTO service_orders (id, customer_id, vehicle_id, status, total_amount)
                         VALUES (:id, :customer_id, :vehicle_id, :status, :total_amount)'
                    );
                    $stmt->execute([
                        ':id'           => $order->getId(),
                        ':customer_id'  => $order->getCustomer()->getId(),
                        ':vehicle_id'   => $order->getVehicle()->getId(),
                        ':status'       => $order->getStatus(),
                        ':total_amount' => $order->getTotalAmount(),
                    ]);
                }
            }

            foreach ($order->getServices() as $service) {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO service_order_services (id, service_order_id, service_catalog_id, price_charged)
                     VALUES (:id, :order_id, :service_id, :price)'
                );
                $stmt->execute([
                    ':id'         => $this->uuidGenerator->generate(),
                    ':order_id'   => $order->getId(),
                    ':service_id' => $service->getId(),
                    ':price'      => $service->getBasePrice(),
                ]);
            }

            foreach ($order->getPartsWithQuantity() as $item) {
                /** @var Part $part */
                $part = $item['part'];
                $quantity = $item['quantity'];

                $stmt = $this->pdo->prepare(
                    'INSERT INTO service_order_parts (id, service_order_id, parts_inventory_id, quantity_used, unit_price_charged)
                     VALUES (:id, :order_id, :part_id, :qty, :price)'
                );
                $stmt->execute([
                    ':id'       => $this->uuidGenerator->generate(),
                    ':order_id' => $order->getId(),
                    ':part_id'  => $part->getId(),
                    ':qty'      => $quantity,
                    ':price'    => $part->getPrice(),
                ]);

                // decrementa estoque atomicamente na mesma transação
                $update = $this->pdo->prepare(
                    'UPDATE parts_inventory SET stock_quantity = stock_quantity - :qty WHERE id = :id'
                );
                $update->execute([':qty' => $quantity, ':id' => $part->getId()]);
            }

            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function updateStatus(ServiceOrder $order): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE service_orders SET status = :status WHERE id = :id'
        );
        $stmt->execute([':status' => $order->getStatus(), ':id' => $order->getId()]);
    }

    /** @param array<string, mixed> $row */
    private function hydrateWithItems(array $row): ServiceOrder
    {
        $customer = Customer::create(
            id: $row['customer_id'],
            name: $row['c_name'],
            document: new Document($row['c_document']),
        );

        $vehicle = Vehicle::create(
            id: $row['vehicle_id'],
            customerId: $row['v_customer_id'],
            licensePlate: new LicensePlate($row['v_plate']),
            brand: $row['v_brand'],
            model: $row['v_model'],
            year: (int)$row['v_year'],
        );

        $services = $this->loadServices($row['id']);
        $partsWithQuantity = $this->loadParts($row['id']);
        $totalAmount = array_sum(array_map(fn(ServiceItem $s) => $s->getBasePrice(), $services))
            + array_sum(array_map(fn(array $p) => $p['part']->getPrice() * $p['quantity'], $partsWithQuantity));

        return ServiceOrder::reconstitute(
            id: $row['id'],
            customer: $customer,
            vehicle: $vehicle,
            status: $row['status'],
            totalAmount: round((float)$row['total_amount'] ?: $totalAmount, 2),
            createdAt: new \DateTimeImmutable($row['created_at']),
            services: $services,
            partsWithQuantity: $partsWithQuantity,
        );
    }

    /** @return ServiceItem[] */
    private function loadServices(string $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sos.price_charged, sc.id AS sc_id, sc.description, sc.estimated_time_minutes
             FROM service_order_services sos
             JOIN service_catalog sc ON sc.id = sos.service_catalog_id
             WHERE sos.service_order_id = :id'
        );
        $stmt->execute([':id' => $orderId]);

        return array_map(fn(array $row) => ServiceItem::create(
            id: $row['sc_id'],
            description: $row['description'],
            basePrice: (float)$row['price_charged'],
            estimatedTimeMinutes: (int)$row['estimated_time_minutes'],
        ), $stmt->fetchAll());
    }

    /** @return array<array{part: Part, quantity: int}> */
    private function loadParts(string $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sop.quantity_used, sop.unit_price_charged,
                    pi.id AS pi_id, pi.description, pi.stock_quantity
             FROM service_order_parts sop
             JOIN parts_inventory pi ON pi.id = sop.parts_inventory_id
             WHERE sop.service_order_id = :id'
        );
        $stmt->execute([':id' => $orderId]);

        return array_map(fn(array $row) => [
            'part'     => Part::create(
                id: $row['pi_id'],
                description: $row['description'],
                price: (float)$row['unit_price_charged'],
                stockQuantity: (int)$row['stock_quantity'],
            ),
            'quantity' => (int)$row['quantity_used'],
        ], $stmt->fetchAll());
    }
}
