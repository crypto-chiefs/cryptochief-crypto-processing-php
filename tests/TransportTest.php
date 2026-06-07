<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Client;
use CryptoChief\Processing\Exception\ApiException;
use CryptoChief\Processing\Sign;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class TransportTest extends TestCase
{
    public function testSignsRequestAndSendsCanonicalBody(): void
    {
        /** @var RequestInterface[] $captured */
        $captured = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode(['amount_to_receive' => '0.0099']) ?: ''),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($captured));
        $http = new GuzzleClient(['handler' => $stack]);

        $client = new Client(
            merchantId: 'M',
            apiKey: 'K',
            httpClient: $http,
        );

        $resp = $client->request('/v1/payout/estimate', ['coin' => 'ETH', 'amount' => '0.01']);
        self::assertIsArray($resp);
        self::assertSame('0.0099', $resp['amount_to_receive']);

        self::assertCount(1, $captured);
        /** @var array{request: RequestInterface} $entry */
        $entry = $captured[0];
        $req = $entry['request'];

        self::assertSame('M', $req->getHeaderLine('Merchant'));
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('cryptochief-php/', $req->getHeaderLine('User-Agent'));

        $body = (string) $req->getBody();
        self::assertSame('{"amount":"0.01","coin":"ETH"}', $body);
        self::assertSame(Sign::sign($body, 'K'), $req->getHeaderLine('Signature'));
    }

    public function testRetriesOn5xx(): void
    {
        $mock = new MockHandler([
            new Response(503, [], '{"error":"SERVICE_ERROR","msg":"BUSY"}'),
            new Response(200, [], '{"ok":true}'),
        ]);
        $stack = HandlerStack::create($mock);
        $http = new GuzzleClient(['handler' => $stack]);

        $client = new Client(
            merchantId: 'M',
            apiKey: 'K',
            retries: 1,
            retryBaseMs: 1.0,
            retryMaxMs: 1.0,
            httpClient: $http,
        );

        $resp = $client->request('/v1/payout/estimate', ['x' => 1]);
        self::assertIsArray($resp);
        self::assertTrue($resp['ok']);
    }

    public function testDoesNotRetryOn4xx(): void
    {
        $mock = new MockHandler([
            new Response(400, [], '{"error":"INVALID_PARAMS","msg":"bad"}'),
        ]);
        $stack = HandlerStack::create($mock);
        $http = new GuzzleClient(['handler' => $stack]);

        $client = new Client(
            merchantId: 'M',
            apiKey: 'K',
            retries: 3,
            httpClient: $http,
        );

        $this->expectException(ApiException::class);
        try {
            $client->request('/v1/payout/estimate', ['x' => 1]);
        } catch (ApiException $e) {
            self::assertSame('bad', $e->errorCode);
            self::assertSame(400, $e->httpStatus);
            self::assertFalse($e->isRetryable());
            throw $e;
        }
    }

    public function testErrorEnvelopeParsing(): void
    {
        $mock = new MockHandler([
            new Response(402, [], '{"error":"INSUFFICIENT_FUNDS"}'),
        ]);
        $stack = HandlerStack::create($mock);
        $http = new GuzzleClient(['handler' => $stack]);

        $client = new Client(
            merchantId: 'M',
            apiKey: 'K',
            retries: 0,
            httpClient: $http,
        );

        try {
            $client->request('/v1/payout/execute');
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('INSUFFICIENT_FUNDS', $e->errorCode);
            self::assertSame(402, $e->httpStatus);
        }
    }
}
