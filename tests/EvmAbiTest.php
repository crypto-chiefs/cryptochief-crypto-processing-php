<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Contract\EvmAbi;
use CryptoChief\Processing\Exception\EvmAbiException;
use PHPUnit\Framework\TestCase;

final class EvmAbiTest extends TestCase
{
    public function testStandardSelectors(): void
    {
        // The four-byte selectors are the contract everyone agrees on.
        self::assertSame('0xa9059cbb', '0x' . bin2hex(EvmAbi::selector('transfer(address,uint256)')));
        self::assertSame('0x095ea7b3', '0x' . bin2hex(EvmAbi::selector('approve(address,uint256)')));
        self::assertSame('0x70a08231', '0x' . bin2hex(EvmAbi::selector('balanceOf(address)')));
        self::assertSame('0x18160ddd', '0x' . bin2hex(EvmAbi::selector('totalSupply()')));
        self::assertSame('0x23b872dd', '0x' . bin2hex(EvmAbi::selector('transferFrom(address,address,uint256)')));
        // Uniswap V2 router
        self::assertSame(
            '0x38ed1739',
            '0x' . bin2hex(EvmAbi::selector('swapExactTokensForTokens(uint256,uint256,address[],address,uint256)'))
        );
    }

    public function testEncodeTransfer(): void
    {
        // transfer(0x1111111111111111111111111111111111111111, 1_000_000)
        $hex = EvmAbi::encodeCallHex(
            'transfer(address,uint256)',
            '0x1111111111111111111111111111111111111111',
            1_000_000
        );
        self::assertSame(
            '0xa9059cbb'
            . '0000000000000000000000001111111111111111111111111111111111111111'
            . '00000000000000000000000000000000000000000000000000000000000f4240',
            $hex
        );
    }

    public function testEncodeDynamicArray(): void
    {
        $hex = EvmAbi::encodeCallHex(
            'multiSend(address[],uint256[])',
            ['0x1111111111111111111111111111111111111111', '0x2222222222222222222222222222222222222222'],
            [100, 200],
        );
        // 4 bytes selector + 64 bytes head + dynamic tails.
        // First tail: addresses len (32) + 2 padded addresses (64). Second tail: uints len + 2 uints.
        // head offsets: 0x40 (64 - first dynamic starts at offset 0x40), 0x40 + 0x60 (96) = 0xa0
        self::assertStringStartsWith(
            '0x'
            . bin2hex(EvmAbi::selector('multiSend(address[],uint256[])'))
            . '0000000000000000000000000000000000000000000000000000000000000040'
            . '00000000000000000000000000000000000000000000000000000000000000a0',
            $hex
        );
        // Validate the address-array payload (len=2, then two padded addresses)
        self::assertStringContainsString(
            '0000000000000000000000000000000000000000000000000000000000000002'
            . '0000000000000000000000001111111111111111111111111111111111111111'
            . '0000000000000000000000002222222222222222222222222222222222222222'
            . '0000000000000000000000000000000000000000000000000000000000000002'
            . '0000000000000000000000000000000000000000000000000000000000000064'
            . '00000000000000000000000000000000000000000000000000000000000000c8',
            $hex
        );
    }

    public function testDynamicBytesAndString(): void
    {
        $hex = EvmAbi::encodeCallHex('bar(bytes,string)', '0x010203', 'hello');
        // Selector + 2 head words (each pointing into the tails) + 2 dynamic payloads.
        $body = substr($hex, 10); // strip selector + 0x prefix bookkeeping
        self::assertStringContainsString('0000000000000000000000000000000000000000000000000000000000000003', $body);
        self::assertStringContainsString(
            // "hello" length=5
            '0000000000000000000000000000000000000000000000000000000000000005'
            . '68656c6c6f000000000000000000000000000000000000000000000000000000',
            $body
        );
    }

    public function testTronAddressInsideAddressSlot(): void
    {
        $hex = EvmAbi::encodeCallHex(
            'transfer(address,uint256)',
            'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
            1
        );
        // The TRON prefix 0x41 is stripped; the 20 trailing bytes pad into the address slot.
        self::assertStringContainsString(
            '000000000000000000000000a614f803b6fd780986a42c78ec9c7f77e6ded13c',
            $hex
        );
    }

    public function testBytesNLengthMismatchRejected(): void
    {
        $this->expectException(EvmAbiException::class);
        EvmAbi::encodeCallHex('foo(bytes32)', '0x0102'); // 2 bytes, not 32
    }

    public function testAliasesNormalize(): void
    {
        self::assertSame(
            EvmAbi::selector('transfer(address,uint256)'),
            EvmAbi::selector('transfer (address , uint )')
        );
        self::assertSame(
            EvmAbi::selector('transfer(address,uint256)'),
            EvmAbi::selector('transfer(address to, uint256 value)')
        );
    }
}
