<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Contract\Base58;
use PHPUnit\Framework\TestCase;

final class Base58Test extends TestCase
{
    public function testRoundTrip(): void
    {
        // Base58::decode rejects an empty input as malformed, so the round-trip set
        // starts at one byte.
        $cases = ['hello', "\x00", "\x00\x00\x00abc", str_repeat("\xff", 20)];
        foreach ($cases as $raw) {
            self::assertSame($raw, Base58::decode(Base58::encode($raw)));
        }
    }

    public function testKnownVector(): void
    {
        // "Hello World" base58
        self::assertSame('JxF12TrwUP45BMd', Base58::encode('Hello World'));
        self::assertSame('Hello World', Base58::decode('JxF12TrwUP45BMd'));
    }

    public function testLeadingZerosPreserved(): void
    {
        self::assertSame("\x00\x00\x00abc", Base58::decode(Base58::encode("\x00\x00\x00abc")));
    }
}
