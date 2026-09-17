<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests\Support;

use CryptoChief\Processing\Sign;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\RequestInterface;

final class SignedRequest
{
    /**
     * The request carries HMAC v1 headers over the bytes sent and no `Signature` header.
     * `$path` defaults to the percent-decoded request URI path.
     */
    public static function assertSignedV1(
        RequestInterface $request,
        string $apiKey = 'K',
        ?string $path = null,
        ?string $query = null,
    ): void {
        Assert::assertFalse($request->hasHeader('Signature'), 'Signature header must not be sent');
        Assert::assertMatchesRegularExpression('/^\d+$/', $request->getHeaderLine(Sign::HEADER_TIMESTAMP));
        Assert::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $request->getHeaderLine(Sign::HEADER_NONCE));

        $expected = Sign::hmacV1Sign(
            apiKey: $apiKey,
            timestamp: $request->getHeaderLine(Sign::HEADER_TIMESTAMP),
            nonce: $request->getHeaderLine(Sign::HEADER_NONCE),
            method: $request->getMethod(),
            path: $path ?? rawurldecode($request->getUri()->getPath()),
            query: $query ?? $request->getUri()->getQuery(),
            merchant: $request->getHeaderLine('Merchant'),
            idempotencyKey: $request->getHeaderLine(Sign::HEADER_IDEMPOTENCY_KEY),
            body: (string) $request->getBody(),
        );

        Assert::assertSame('v1=' . $expected, $request->getHeaderLine(Sign::HEADER_SIGNATURE));
    }
}
