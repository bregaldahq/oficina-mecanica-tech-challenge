<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ValueObject;

use App\Domain\Exception\InvalidDocumentException;
use App\Domain\ValueObject\Document;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
class CpfCnpjTest extends TestCase
{
    public function testValidCpfWithMask(): void
    {
        $doc = new Document('529.982.247-25');
        $this->assertSame('52998224725', $doc->getValue());
    }

    public function testValidCpfRawDigits(): void
    {
        $doc = new Document('52998224725');
        $this->assertSame('52998224725', $doc->getValue());
    }

    public function testAllSameDigitCpfIsInvalid(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new Document('111.111.111-11');
    }

    public function testInvalidCpfCheckDigit(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new Document('529.982.247-00');
    }

    public function testCpfWithWrongLength(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new Document('12345');
    }

    public function testValidCnpjWithMask(): void
    {
        $doc = new Document('11.222.333/0001-81');
        $this->assertSame('11222333000181', $doc->getValue());
    }

    public function testValidCnpjRawDigits(): void
    {
        $doc = new Document('11222333000181');
        $this->assertSame('11222333000181', $doc->getValue());
    }

    public function testAllSameDigitCnpjIsInvalid(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new Document('00.000.000/0000-00');
    }

    public function testInvalidCnpjCheckDigit(): void
    {
        $this->expectException(InvalidDocumentException::class);
        new Document('11.222.333/0001-00');
    }

    public function testEquality(): void
    {
        $a = new Document('529.982.247-25');
        $b = new Document('52998224725');
        $this->assertTrue($a->equals($b));
    }
}
