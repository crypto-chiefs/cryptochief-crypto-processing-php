<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Sign;
use PHPUnit\Framework\TestCase;

/**
 * Signature regression vectors. A drift in canonical JSON or MD5 wiring fails here
 * before it can fail against the live API.
 *
 * Secret: "test_api_key_123".
 */
final class SignTest extends TestCase
{
    private const SECRET = 'test_api_key_123';

    public function testPayoutEstimateVector(): void
    {
        $body = [
            'network' => 'ETH_SEPOLIA',
            'coin' => 'ETH',
            'amount' => '0.0001',
            'to_address' => '0xAbC',
            'from_addresses' => ['0x111', '0x222'],
        ];
        $canonical = Sign::canonicalJson($body);
        self::assertSame(
            '{"amount":"0.0001","coin":"ETH","from_addresses":["0x111","0x222"],'
            . '"network":"ETH_SEPOLIA","to_address":"0xAbC"}',
            $canonical
        );
        self::assertSame('97bd68e4e4dc86b6dad8aa06e1f7b63d', Sign::sign($canonical, self::SECRET));
    }

    public function testBatchPayoutHtmlEscapedUrl(): void
    {
        $body = [
            'items' => [
                ['order_id' => 'b', 'user_id' => 'u', 'amount' => '1'],
                ['order_id' => 'a', 'user_id' => 'u2', 'amount' => '2'],
            ],
            'url_callback' => 'https://x.io/cb?a=1&b=2',
        ];
        self::assertSame(
            '8b85b5464c9a92059a74039d7a008618',
            Sign::sign(Sign::canonicalJson($body), self::SECRET)
        );
    }

    public function testNestedMapArrayHtmlChars(): void
    {
        $body = [
            'z' => true,
            'a' => 1,
            'm' => ['y' => '<tag>', 'x' => 'a&b'],
            'arr' => [3, 2, 1],
        ];
        self::assertSame(
            '5fcfb2c41ee9d91073b9adcf22fe8a79',
            Sign::sign(Sign::canonicalJson($body), self::SECRET)
        );
    }

    public function testEmptyBody(): void
    {
        self::assertSame('{}', Sign::canonicalJson([]));
        self::assertSame('33d8723e69fba9d68b8991ad200be4b3', Sign::sign(Sign::canonicalJson([]), self::SECRET));
    }

    public function testNullSignsAsMd5OfKey(): void
    {
        self::assertSame('', Sign::canonicalJson(null));
        self::assertSame(
            Sign::sign('', self::SECRET),
            Sign::sign(Sign::canonicalJson(null), self::SECRET)
        );
    }

    public function testDropsNullsKeepsEmpties(): void
    {
        self::assertSame('{"a":"x"}', Sign::canonicalJson(['b' => null, 'a' => 'x', 'c' => null]));
        self::assertSame('{"a":"","b":[]}', Sign::canonicalJson(['a' => '', 'b' => []]));
    }

    public function testHtmlEscapesAndSeparators(): void
    {
        self::assertSame(
            '{"k":"\\u003ca\\u003e\\u0026\\u2028\\u2029"}',
            Sign::canonicalJson(['k' => "<a>&\u{2028}\u{2029}"])
        );
    }

    public function testSignValueReturnsCanonicalAndSignature(): void
    {
        [$canonical, $signature] = Sign::signValue(['a' => 1], self::SECRET);
        self::assertSame('{"a":1}', $canonical);
        self::assertSame(md5(base64_encode($canonical) . self::SECRET), $signature);
    }
}
