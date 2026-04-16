<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Entity;

use App\Domain\Entity\Vehicle;
use App\Domain\Exception\DomainException;
use App\Domain\Exception\InvalidLicensePlateException;
use App\Domain\ValueObject\LicensePlate;
use PHPUnit\Framework\TestCase;

class VehicleTest extends TestCase
{
    public function testCreateWithValidData(): void
    {
        $vehicle = Vehicle::create('veh-1', 'cust-1', new LicensePlate('ABC-1234'), 'Toyota', 'Corolla', 2020);

        $this->assertSame('veh-1', $vehicle->getId());
        $this->assertSame('cust-1', $vehicle->getCustomerId());
        $this->assertSame('Toyota', $vehicle->getBrand());
        $this->assertSame('Corolla', $vehicle->getModel());
        $this->assertSame(2020, $vehicle->getYear());
    }

    public function testToArrayContainsExpectedKeys(): void
    {
        $vehicle = Vehicle::create('veh-1', 'cust-1', new LicensePlate('ABC-1234'), 'Toyota', 'Corolla', 2020);
        $array   = $vehicle->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('customer_id', $array);
        $this->assertArrayHasKey('license_plate', $array);
        $this->assertArrayHasKey('brand', $array);
        $this->assertArrayHasKey('model', $array);
        $this->assertArrayHasKey('year', $array);
    }

    public function testInvalidYearBelowMinimumThrows(): void
    {
        $this->expectException(DomainException::class);
        Vehicle::create('veh-1', 'cust-1', new LicensePlate('ABC-1234'), 'Ford', 'T', 1885);
    }

    public function testInvalidYearAboveMaximumThrows(): void
    {
        $this->expectException(DomainException::class);
        $futureYear = (int)date('Y') + 2;
        Vehicle::create('veh-1', 'cust-1', new LicensePlate('ABC-1234'), 'Future', 'Car', $futureYear);
    }

    public function testOldestValidYear(): void
    {
        $vehicle = Vehicle::create('veh-1', 'cust-1', new LicensePlate('ABC-1234'), 'Benz', 'Patent-Motorwagen', 1886);
        $this->assertSame(1886, $vehicle->getYear());
    }

    public function testMercosulLicensePlate(): void
    {
        $vehicle = Vehicle::create('veh-1', 'cust-1', new LicensePlate('ABC1D23'), 'Fiat', 'Pulse', 2022);
        $this->assertTrue($vehicle->getLicensePlate()->isMercosul());
    }

    public function testInvalidLicensePlateThrows(): void
    {
        $this->expectException(InvalidLicensePlateException::class);
        new LicensePlate('INVALID');
    }
}
