<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Contract\Borsh;
use PHPUnit\Framework\TestCase;

final class BorshTest extends TestCase
{
    public function testU64LittleEndian(): void
    {
        self::assertSame(bin2hex("\x01\x00\x00\x00\x00\x00\x00\x00"), bin2hex(Borsh::u64(1)->encode()));
        self::assertSame(bin2hex("\xff\xff\xff\xff\xff\xff\xff\xff"), bin2hex(Borsh::u64('18446744073709551615')->encode()));
    }

    public function testU128(): void
    {
        $enc = Borsh::u128('340282366920938463463374607431768211455')->encode();
        self::assertSame(str_repeat('ff', 16), bin2hex($enc));
    }

    public function testStringLenPrefix(): void
    {
        $enc = Borsh::string('abc')->encode();
        self::assertSame(bin2hex("\x03\x00\x00\x00abc"), bin2hex($enc));
    }

    public function testBool(): void
    {
        self::assertSame("\x01", Borsh::bool(true)->encode());
        self::assertSame("\x00", Borsh::bool(false)->encode());
    }

    public function testVec(): void
    {
        $enc = Borsh::vec([Borsh::u32(1), Borsh::u32(2), Borsh::u32(3)])->encode();
        self::assertSame(
            bin2hex("\x03\x00\x00\x00") . bin2hex("\x01\x00\x00\x00\x02\x00\x00\x00\x03\x00\x00\x00"),
            bin2hex($enc)
        );
    }

    public function testOptionNoneSome(): void
    {
        self::assertSame("\x00", Borsh::option(null)->encode());
        self::assertSame("\x01" . "\x05\x00\x00\x00\x00\x00\x00\x00", Borsh::option(Borsh::u64(5))->encode());
    }

    public function testFixedBytesPubkey(): void
    {
        // Known Solana pubkey "11111111111111111111111111111111" (system program) is 32 zero bytes.
        $sysProgram = '11111111111111111111111111111111';
        $enc = Borsh::pubkey($sysProgram)->encode();
        self::assertSame(32, strlen($enc));
        self::assertSame(str_repeat("\x00", 32), $enc);
    }

    public function testAnchorDiscriminatorFormula(): void
    {
        $disc = Borsh::anchorDiscriminator('initialize');
        $expected = substr(hash('sha256', 'global:initialize', true), 0, 8);
        self::assertSame($expected, $disc);
        self::assertSame(8, strlen($disc));
    }

    public function testEncodeAnchorInstruction(): void
    {
        $data = Borsh::encodeAnchorInstruction(
            'transfer',
            Borsh::u64(1_000_000),
            Borsh::string('memo'),
        );
        self::assertSame(8 + 8 + 4 + 4, strlen($data));
        self::assertSame(Borsh::anchorDiscriminator('transfer'), substr($data, 0, 8));
    }
}
