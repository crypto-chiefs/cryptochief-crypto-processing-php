<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Amount;
use CryptoChief\Processing\Exception\InvalidAmountException;
use PHPUnit\Framework\TestCase;

final class AmountTest extends TestCase
{
    public function testHumanToBase18Decimals(): void
    {
        self::assertSame('1500000000000000000', Amount::humanToBase('1.5', 18));
        self::assertSame('1000000000000000', Amount::humanToBase('0.001', 18));
        self::assertSame('0', Amount::humanToBase('0', 18));
        self::assertSame('0', Amount::humanToBase('0.000000000000000000', 18));
    }

    public function testHumanToBase8Decimals(): void
    {
        self::assertSame('10000', Amount::humanToBase('0.0001', 8));
        self::assertSame('100000000', Amount::humanToBase('1', 8));
        self::assertSame('100000010', Amount::humanToBase('1.0000001', 8));
    }

    public function testHumanToBaseTruncatesExtraPrecision(): void
    {
        // 19 decimals collapses to 18 - the 19th digit is dropped.
        self::assertSame('1230000000000000000', Amount::humanToBase('1.2300000000000000001', 18));
    }

    public function testBaseToHuman(): void
    {
        self::assertSame('1.5', Amount::baseToHuman('1500000000000000000', 18));
        self::assertSame('0.0001', Amount::baseToHuman('10000', 8));
        self::assertSame('0', Amount::baseToHuman('0', 18));
        self::assertSame('1', Amount::baseToHuman('1000000000000000000', 18));
    }

    public function testRoundTrip(): void
    {
        $values = ['1.5', '0.0001', '0', '123456789.987654321'];
        foreach ($values as $v) {
            self::assertSame($v, Amount::baseToHuman(Amount::humanToBase($v, 18), 18));
        }
    }

    public function testNanoTon(): void
    {
        self::assertSame('50000000', Amount::nanoTon('0.05'));
        self::assertSame('1000000000', Amount::nanoTon('1'));
    }

    public function testRejectsNegative(): void
    {
        $this->expectException(InvalidAmountException::class);
        Amount::humanToBase('-1', 18);
    }

    public function testRejectsScientific(): void
    {
        $this->expectException(InvalidAmountException::class);
        Amount::humanToBase('1e5', 18);
    }

    public function testRejectsEmpty(): void
    {
        $this->expectException(InvalidAmountException::class);
        Amount::humanToBase('', 18);
    }

    public function testRejectsTrailingDot(): void
    {
        $this->expectException(InvalidAmountException::class);
        Amount::humanToBase('1.', 18);
    }
}
