<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Aggregate\ServiceOrder;
use App\Domain\Entity\Customer;
use App\Domain\Entity\Part;
use App\Domain\Entity\Vehicle;
use App\Domain\ValueObject\Document;
use App\Domain\ValueObject\LicensePlate;
use App\Infrastructure\Database\PdoConnection;
use App\Infrastructure\Repository\PdoCustomerRepository;
use App\Infrastructure\Repository\PdoPartRepository;
use App\Infrastructure\Repository\PdoServiceOrderRepository;
use App\Infrastructure\Repository\PdoVehicleRepository;
use App\Infrastructure\UuidGenerator;
use PDO;
use PHPUnit\Framework\TestCase;

class PdoServiceOrderRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PdoServiceOrderRepository $repo;
    private PdoCustomerRepository $customerRepo;
    private PdoVehicleRepository $vehicleRepo;
    private PdoPartRepository $partRepo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        PdoConnection::setInstance($this->pdo);
        $this->createSchema();

        $uuid               = new UuidGenerator();
        $this->repo         = new PdoServiceOrderRepository($uuid);
        $this->customerRepo = new PdoCustomerRepository();
        $this->vehicleRepo  = new PdoVehicleRepository();
        $this->partRepo     = new PdoPartRepository();
    }

    protected function tearDown(): void
    {
        PdoConnection::reset();
    }

    private function createSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE customers (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                document TEXT NOT NULL UNIQUE,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pdo->exec('
            CREATE TABLE vehicles (
                id TEXT PRIMARY KEY,
                customer_id TEXT NOT NULL,
                license_plate TEXT NOT NULL UNIQUE,
                brand TEXT NOT NULL,
                model TEXT NOT NULL,
                year INTEGER NOT NULL,
                FOREIGN KEY (customer_id) REFERENCES customers(id)
            )
        ');
        $this->pdo->exec('
            CREATE TABLE service_catalog (
                id TEXT PRIMARY KEY,
                description TEXT NOT NULL,
                base_price REAL NOT NULL,
                estimated_time_minutes INTEGER NOT NULL
            )
        ');
        $this->pdo->exec('
            CREATE TABLE parts_inventory (
                id TEXT PRIMARY KEY,
                description TEXT NOT NULL,
                price REAL NOT NULL,
                stock_quantity INTEGER NOT NULL DEFAULT 0
            )
        ');
        $this->pdo->exec('
            CREATE TABLE service_orders (
                id TEXT PRIMARY KEY,
                customer_id TEXT NOT NULL,
                vehicle_id TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT \'RECEIVED\',
                total_amount REAL NOT NULL DEFAULT 0.00,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (customer_id) REFERENCES customers(id),
                FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
            )
        ');
        $this->pdo->exec('
            CREATE TABLE service_order_services (
                id TEXT PRIMARY KEY,
                service_order_id TEXT NOT NULL,
                service_catalog_id TEXT NOT NULL,
                price_charged REAL NOT NULL,
                FOREIGN KEY (service_order_id) REFERENCES service_orders(id),
                FOREIGN KEY (service_catalog_id) REFERENCES service_catalog(id)
            )
        ');
        $this->pdo->exec('
            CREATE TABLE service_order_parts (
                id TEXT PRIMARY KEY,
                service_order_id TEXT NOT NULL,
                parts_inventory_id TEXT NOT NULL,
                quantity_used INTEGER NOT NULL,
                unit_price_charged REAL NOT NULL,
                FOREIGN KEY (service_order_id) REFERENCES service_orders(id),
                FOREIGN KEY (parts_inventory_id) REFERENCES parts_inventory(id)
            )
        ');
    }

    private function makeCustomer(string $id = 'cust-1'): Customer
    {
        return Customer::create($id, 'João Silva', new Document('529.982.247-25'));
    }

    private function makeVehicle(string $id = 'veh-1', string $customerId = 'cust-1'): Vehicle
    {
        return Vehicle::create($id, $customerId, new LicensePlate('ABC-1234'), 'Toyota', 'Corolla', 2020);
    }

    public function testSavesAndFindsOrder(): void
    {
        $customer = $this->makeCustomer();
        $vehicle  = $this->makeVehicle();
        $this->customerRepo->save($customer);
        $this->vehicleRepo->save($vehicle);

        $order = ServiceOrder::create('order-1', $customer, $vehicle);
        $this->repo->save($order);

        $found = $this->repo->findById('order-1');

        $this->assertNotNull($found);
        $this->assertSame('order-1', $found->getId());
        $this->assertSame('RECEIVED', $found->getStatus());
    }

    public function testTransactionRollbackOnDuplicatePrimaryKey(): void
    {
        $customer = $this->makeCustomer();
        $vehicle  = $this->makeVehicle();
        $this->customerRepo->save($customer);
        $this->vehicleRepo->save($vehicle);

        $order1 = ServiceOrder::create('order-dup', $customer, $vehicle);
        $this->repo->save($order1);

        $order2          = ServiceOrder::create('order-dup', $customer, $vehicle);
        $exceptionThrown = false;

        try {
            $this->repo->save($order2);
        } catch (\Exception) {
            $exceptionThrown = true;
        }

        $this->assertTrue($exceptionThrown);

        $found = $this->repo->findById('order-dup');
        $this->assertNotNull($found);
        $this->assertSame('RECEIVED', $found->getStatus());
    }

    public function testStockIsDecrementedOnSaveWithParts(): void
    {
        $customer = $this->makeCustomer();
        $vehicle  = $this->makeVehicle();
        $this->customerRepo->save($customer);
        $this->vehicleRepo->save($vehicle);

        $part = Part::create('p-1', 'Filtro de óleo', 45.00, 10);
        $this->partRepo->save($part);

        $order = ServiceOrder::create('order-1', $customer, $vehicle);
        $order->addPart(Part::create('p-1', 'Filtro de óleo', 45.00, 10), 3);
        $this->repo->save($order);

        $updated = $this->partRepo->findById('p-1');
        $this->assertNotNull($updated);
        $this->assertSame(7, $updated->getStockQuantity());
    }

    public function testUpdateStatusPersists(): void
    {
        $customer = $this->makeCustomer();
        $vehicle  = $this->makeVehicle();
        $this->customerRepo->save($customer);
        $this->vehicleRepo->save($vehicle);

        $order = ServiceOrder::create('order-1', $customer, $vehicle);
        $this->repo->save($order);

        $order->changeToDiagnosis();
        $this->repo->updateStatus($order);

        $found = $this->repo->findById('order-1');
        $this->assertNotNull($found);
        $this->assertSame('DIAGNOSIS', $found->getStatus());
    }

    public function testFindByDocumentAndLicensePlate(): void
    {
        $customer = $this->makeCustomer();
        $vehicle  = $this->makeVehicle();
        $this->customerRepo->save($customer);
        $this->vehicleRepo->save($vehicle);

        $order = ServiceOrder::create('order-1', $customer, $vehicle);
        $this->repo->save($order);

        $results = $this->repo->findByDocumentAndLicensePlate('52998224725', 'ABC1234');

        $this->assertCount(1, $results);
        $this->assertSame('order-1', $results[0]->getId());
    }

    public function testFindByDocumentAndLicensePlateWithStatusFilter(): void
    {
        $customer = $this->makeCustomer();
        $vehicle  = $this->makeVehicle();
        $this->customerRepo->save($customer);
        $this->vehicleRepo->save($vehicle);

        $order = ServiceOrder::create('order-1', $customer, $vehicle);
        $this->repo->save($order);

        $results = $this->repo->findByDocumentAndLicensePlate('52998224725', 'ABC1234', 'DIAGNOSIS');
        $this->assertCount(0, $results);

        $results = $this->repo->findByDocumentAndLicensePlate('52998224725', 'ABC1234', 'RECEIVED');
        $this->assertCount(1, $results);
    }
}
