<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Exception\CryptoChiefException;
use CryptoChief\Processing\Ton\Address;
use CryptoChief\Processing\Ton\TonAddress;
use PHPUnit\Framework\TestCase;

final class TonAddressTest extends TestCase
{
    public function testCrc16XmodemVector(): void
    {
        // The canonical CRC-16/XMODEM vector: ASCII "123456789" -> 0x31C3.
        self::assertSame(0x31C3, Address::crc16Xmodem('123456789'));
    }

    public function testParseUsdtJettonMaster(): void
    {
        // USDT Jetton master on TON mainnet.
        $eq = 'EQCxE6mUtQJKFnGfaROTKOt1lZbDiiX1kCixRv7Nw2Id_sDs';
        $addr = Address::parse($eq);
        self::assertInstanceOf(TonAddress::class, $addr);
        self::assertSame(0, $addr->workchain);
        self::assertTrue($addr->bounceable);
        self::assertFalse($addr->testnet);
        self::assertSame(32, strlen($addr->hash));
    }

    public function testFriendlyRoundTrip(): void
    {
        $eq = 'EQCxE6mUtQJKFnGfaROTKOt1lZbDiiX1kCixRv7Nw2Id_sDs';
        $addr = Address::parse($eq);
        self::assertSame($eq, Address::toString($addr));
    }

    public function testRawForm(): void
    {
        $eq = 'EQCxE6mUtQJKFnGfaROTKOt1lZbDiiX1kCixRv7Nw2Id_sDs';
        $addr = Address::parse($eq);
        $raw = Address::toRaw($addr);
        // Raw form is parseable too.
        $back = Address::parse($raw);
        self::assertSame($addr->workchain, $back->workchain);
        self::assertSame($addr->hash, $back->hash);
    }

    public function testRejectsBadCrc(): void
    {
        $this->expectException(CryptoChiefException::class);
        // Last char tampered.
        Address::parse('EQCxE6mUtQJKFnGfaROTKOt1lZbDiiX1kCixRv7Nw2Id_sDX');
    }
}
