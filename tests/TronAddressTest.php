<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Contract\TronAddress;
use CryptoChief\Processing\Exception\CryptoChiefException;
use PHPUnit\Framework\TestCase;

final class TronAddressTest extends TestCase
{
    public function testUsdtTrc20RoundTrip(): void
    {
        // USDT TRC-20 contract address.
        $base58 = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
        $hex = TronAddress::toHex($base58);
        self::assertSame('0x41a614f803b6fd780986a42c78ec9c7f77e6ded13c', $hex);
        self::assertSame($base58, TronAddress::fromHex($hex));
    }

    public function testRoundTripIsStable(): void
    {
        $base58 = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
        for ($i = 0; $i < 3; $i++) {
            $base58 = TronAddress::fromHex(TronAddress::toHex($base58));
        }
        self::assertSame('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', $base58);
    }

    public function test20ByteHexAccepted(): void
    {
        // strip the 0x41 prefix to feed a 20-byte form
        $base58 = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
        $hex = substr(TronAddress::toHex($base58), 4); // drop "0x41"
        self::assertSame($base58, TronAddress::fromHex('0x' . $hex));
    }

    public function testRejectsBadChecksum(): void
    {
        $this->expectException(CryptoChiefException::class);
        // Last char tampered
        TronAddress::toHex('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6X');
    }

    public function testRejectsBadLeadingByte(): void
    {
        $this->expectException(CryptoChiefException::class);
        TronAddress::fromHex('0x42a614f803b6fd780986a42c78ec9c7f77e6ded13c');
    }
}
