<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ValueObject;

use App\Domain\Exception\InvalidLicensePlateException;
use App\Domain\ValueObject\LicensePlate;
use PHPUnit\Framework\TestCase;

class LicensePlateTest extends TestCase
{
    public function testValidOldFormatWithDash(): void
    {
        $plate = new LicensePlate('ABC-1234');
        $this->assertSame('ABC1234', $plate->getValue());
    }

    public function testValidOldFormatWithoutDash(): void
    {
        $plate = new LicensePlate('ABC1234');
        $this->assertSame('ABC1234', $plate->getValue());
    }

    public function testOldFormatLowercase(): void
    {
        $plate = new LicensePlate('abc-1234');
        $this->assertSame('ABC1234', $plate->getValue());
    }

    public function testOldFormatGetFormatted(): void
    {
        $plate = new LicensePlate('ABC1234');
        $this->assertSame('ABC-1234', $plate->getFormatted());
    }

    public function testOldFormatIsNotMercosul(): void
    {
        $plate = new LicensePlate('ABC1234');
        $this->assertFalse($plate->isMercosul());
    }

    public function testValidMercosulFormat(): void
    {
        $plate = new LicensePlate('ABC1D23');
        $this->assertSame('ABC1D23', $plate->getValue());
    }

    public function testMercosulLowercase(): void
    {
        $plate = new LicensePlate('abc1d23');
        $this->assertSame('ABC1D23', $plate->getValue());
    }

    public function testMercosulIsMercosul(): void
    {
        $plate = new LicensePlate('ABC1D23');
        $this->assertTrue($plate->isMercosul());
    }

    public function testMercosulGetFormattedReturnsWithoutDash(): void
    {
        $plate = new LicensePlate('ABC1D23');
        $this->assertSame('ABC1D23', $plate->getFormatted());
    }

    public function testInvalidFormatThrows(): void
    {
        $this->expectException(InvalidLicensePlateException::class);
        new LicensePlate('12ABCD');
    }

    public function testEmptyStringThrows(): void
    {
        $this->expectException(InvalidLicensePlateException::class);
        new LicensePlate('');
    }

    public function testTooShortThrows(): void
    {
        $this->expectException(InvalidLicensePlateException::class);
        new LicensePlate('AB123');
    }

    public function testTooLongThrows(): void
    {
        $this->expectException(InvalidLicensePlateException::class);
        new LicensePlate('ABCD12345');
    }

    public function testEquality(): void
    {
        $a = new LicensePlate('ABC-1234');
        $b = new LicensePlate('abc1234');
        $this->assertTrue($a->equals($b));
    }
}
