<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ValueObject;

use App\Domain\Exception\DomainException;
use App\Domain\ValueObject\CustomerStatus;
use PHPUnit\Framework\TestCase;

class CustomerStatusTest extends TestCase
{
    public function testDefaultsToActiveWhenAbsent(): void
    {
        $this->assertSame(CustomerStatus::ACTIVE, CustomerStatus::fromString(null));
        $this->assertSame(CustomerStatus::ACTIVE, CustomerStatus::fromString(''));
    }

    public function testParsesEveryContractValueCaseInsensitively(): void
    {
        $this->assertSame(CustomerStatus::ACTIVE, CustomerStatus::fromString('active'));
        $this->assertSame(CustomerStatus::INACTIVE, CustomerStatus::fromString('INACTIVE'));
        $this->assertSame(CustomerStatus::BLOCKED, CustomerStatus::fromString('Blocked'));
    }

    public function testRejectsUnknownStatus(): void
    {
        $this->expectException(DomainException::class);
        CustomerStatus::fromString('SUSPENDED');
    }

    public function testOnlyActiveCustomersMayAuthenticate(): void
    {
        $this->assertTrue(CustomerStatus::ACTIVE->canAuthenticate());
        $this->assertFalse(CustomerStatus::INACTIVE->canAuthenticate());
        $this->assertFalse(CustomerStatus::BLOCKED->canAuthenticate());
    }
}
