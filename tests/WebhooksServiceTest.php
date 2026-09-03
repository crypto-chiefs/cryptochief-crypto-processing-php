<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Client;
use CryptoChief\Processing\ErrorCode;
use CryptoChief\Processing\Exception\ApiException;
use CryptoChief\Processing\Webhook;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * The outbound-webhook surface: reading a delivery with its attempts, the three
 * routes, and that a refusal is an ApiException with the machine code rather
 * than a queued=false result.
 */
final class WebhooksServiceTest extends TestCase
{
    private const DELIVERY = [
        'uuid' => '44444444-4444-4444-8444-444444444444',
        'event_type' => 'invoice.paid',
        'reference' => 'order-1',
        'target_url' => 'https://m.example/hook',
        'status' => 'failed',
        'attempts' => 3,
        'max_attempts' => 10,
        'resend_count' => 1,
        'last_error' => 'HTTP 500',
        'last_http_status' => 500,
        'next_attempt_at' => null,
        'delivered_at' => null,
        'created_at' => '2026-09-03T10:00:00Z',
        'superseded_by' => null,
        'attempt_history' => [
            [
                'attempt' => 3, 'http_status' => 500, 'error' => 'HTTP 500', 'duration_ms' => 120,
                'target_url' => 'https://m.example/hook', 'created_at' => '2026-09-03T10:02:00Z',
                'response_body' => '<html>oops', 'response_content_type' => 'text/html', 'response_truncated' => true,
            ],
            [
                'attempt' => 2, 'http_status' => null, 'error' => 'dial tcp: connection refused', 'duration_ms' => null,
                'target_url' => 'https://m.example/hook', 'created_at' => null,
                'response_body' => null, 'response_content_type' => null, 'response_truncated' => false,
            ],
        ],
        'payload' => ['body' => '{"event":"invoice.paid"}', 'bytes' => 24, 'truncated' => false],
    ];

    /**
     * @param RequestInterface[] $captured
     */
    private static function client(array &$captured, int $status, array $payload): Client
    {
        $mock = new MockHandler([new Response($status, ['Content-Type' => 'application/json'], json_encode($payload) ?: '')]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($captured));

        return new Client(merchantId: 'M', apiKey: 'K', httpClient: new GuzzleClient(['handler' => $stack]));
    }

    public function testInfoReadsAttemptsAndKeepsNullAsNotRecorded(): void
    {
        $captured = [];
        $client = self::client($captured, 200, self::DELIVERY);

        $d = $client->webhooks()->info(self::DELIVERY['uuid']);

        /** @var array{request: RequestInterface} $entry */
        $entry = $captured[0];
        self::assertSame('/v1/webhooks/info', $entry['request']->getUri()->getPath());
        self::assertSame(['uuid' => self::DELIVERY['uuid']], json_decode((string) $entry['request']->getBody(), true));

        self::assertSame('failed', $d->status);
        self::assertSame(500, $d->lastHttpStatus);
        self::assertNull($d->deliveredAt);
        self::assertNull($d->supersededBy);
        self::assertNotNull($d->attemptHistory);
        self::assertCount(2, $d->attemptHistory);
        [$answered, $silent] = $d->attemptHistory;
        self::assertTrue($answered->responseTruncated);
        self::assertSame('text/html', $answered->responseContentType);
        // An attempt nothing answered has no status and no body — only the error.
        self::assertNull($silent->httpStatus);
        self::assertNull($silent->responseBody);
        self::assertNull($silent->createdAt);
        self::assertStringContainsString('connection refused', (string) $silent->error);
        self::assertNotNull($d->payload);
        self::assertSame(24, $d->payload->bytes);
    }

    public function testResendStaticDepositIsAddressedByTheDepositUuid(): void
    {
        $captured = [];
        $client = self::client($captured, 200, [
            'uuid' => 'dep-1',
            'deliveries' => [[
                'uuid' => 'd-1', 'event_type' => 'static_deposit.paid', 'reference' => 'dep-1',
                'status' => 'delivered', 'queued' => true, 'attempts' => 2, 'resend_count' => 1,
            ]],
            'queued' => 1,
            'total' => 1,
        ]);

        $out = $client->webhooks()->resendStaticDeposit('dep-1');

        /** @var array{request: RequestInterface} $entry */
        $entry = $captured[0];
        self::assertSame('/v1/static-deposits/resend', $entry['request']->getUri()->getPath());
        self::assertSame(['uuid' => 'dep-1'], json_decode((string) $entry['request']->getBody(), true));
        self::assertSame(1, $out->queued);
        self::assertNotNull($out->deliveries);
        self::assertTrue($out->deliveries[0]->queued);
        self::assertSame(1, $out->deliveries[0]->resendCount);
    }

    public function testRefusalIsAnApiExceptionWithTheCode(): void
    {
        $captured = [];
        $client = self::client($captured, 409, [
            'ok' => false,
            'error' => 'DELIVERY_SUPERSEDED',
            'msg' => 'not the latest; resend invoice.paid instead',
            'superseded_by' => 'invoice.paid',
        ]);

        try {
            $client->webhooks()->resend(self::DELIVERY['uuid']);
            self::fail('a refusal must throw');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::DeliverySuperseded->value, $e->errorCode);
            self::assertSame(409, $e->httpStatus);
            self::assertStringContainsString('invoice.paid', $e->getMessage());
        }
    }

    public function testDeliveryHeaderName(): void
    {
        self::assertSame('X-Webhook-Delivery', Webhook::DELIVERY_HEADER);
    }
}
