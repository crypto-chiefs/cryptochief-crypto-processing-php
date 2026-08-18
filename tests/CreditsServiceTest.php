<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Client;
use CryptoChief\Processing\Dto\CreditsTopupRequest;
use CryptoChief\Processing\Sign;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class CreditsServiceTest extends TestCase
{
    public function testBalanceSignsEmptyBodyAndMapsResponse(): void
    {
        /** @var RequestInterface[] $captured */
        $captured = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'credits_balance' => -15200000,
                'usd_balance' => '-1.52',
                'is_postpaid' => true,
                'debt_limit_credits' => 500000000,
                'can_execute_gas_operations' => true,
                'gas_ops_min_credits' => 3000000,
                'timestamp' => '2026-08-18T12:34:56Z',
            ]) ?: ''),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($captured));
        $http = new GuzzleClient(['handler' => $stack]);

        $client = new Client(
            merchantId: 'M',
            apiKey: 'K',
            httpClient: $http,
        );

        $balance = $client->credits()->balance();

        self::assertSame(-15200000, $balance->creditsBalance);
        self::assertSame('-1.52', $balance->usdBalance);
        self::assertTrue($balance->isPostpaid);
        self::assertSame(500000000, $balance->debtLimitCredits);
        self::assertTrue($balance->canExecuteGasOperations);
        self::assertSame(3000000, $balance->gasOpsMinCredits);
        self::assertSame('2026-08-18T12:34:56Z', $balance->timestamp);

        self::assertCount(1, $captured);
        /** @var array{request: RequestInterface} $entry */
        $entry = $captured[0];
        $req = $entry['request'];

        self::assertSame('POST', $req->getMethod());
        self::assertSame('/v1/credits/balance', $req->getUri()->getPath());
        self::assertSame('M', $req->getHeaderLine('Merchant'));
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));

        // The empty request canonicalizes to `{}` and is signed like every other call.
        $body = (string) $req->getBody();
        self::assertSame('{}', $body);
        self::assertSame(Sign::sign($body, 'K'), $req->getHeaderLine('Signature'));
    }

    public function testBalancePrepaidPositive(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'credits_balance' => 25000000,
                'usd_balance' => '2.50',
                'is_postpaid' => false,
                'debt_limit_credits' => 0,
                'can_execute_gas_operations' => true,
                'gas_ops_min_credits' => 3000000,
                'timestamp' => '2026-08-18T12:34:56Z',
            ]) ?: ''),
        ]);
        $stack = HandlerStack::create($mock);
        $http = new GuzzleClient(['handler' => $stack]);

        $client = new Client(
            merchantId: 'M',
            apiKey: 'K',
            httpClient: $http,
        );

        $balance = $client->credits()->balance();

        self::assertSame(25000000, $balance->creditsBalance);
        self::assertSame('2.50', $balance->usdBalance);
        self::assertFalse($balance->isPostpaid);
        self::assertSame(0, $balance->debtLimitCredits);
        self::assertTrue($balance->canExecuteGasOperations);
    }

    public function testTopupOmitsEmptyOptionalUrlsAndMapsFullResponse(): void
    {
        /** @var RequestInterface[] $captured */
        $captured = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'invoice_id' => 90210,
                'payment_link' => 'https://pay.crypto-chief.com/topup/abc123',
                'amount' => '250.00',
                'currency' => 'USDT',
                'status' => 'pending',
                'order_uuid' => '7f425aff-be55-4151-a1bc-03108aea1be4',
                'expired_at' => 1755522896,
            ]) ?: ''),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($captured));
        $http = new GuzzleClient(['handler' => $stack]);

        $client = new Client(
            merchantId: 'M',
            apiKey: 'K',
            httpClient: $http,
        );

        $topup = $client->credits()->topup(new CreditsTopupRequest(
            amount: '250.00',
            currency: 'USDT',
        ));

        self::assertSame(90210, $topup->invoiceId);
        self::assertSame('https://pay.crypto-chief.com/topup/abc123', $topup->paymentLink);
        self::assertSame('250.00', $topup->amount);
        self::assertSame('USDT', $topup->currency);
        self::assertSame('pending', $topup->status);
        self::assertSame('7f425aff-be55-4151-a1bc-03108aea1be4', $topup->orderUuid);
        self::assertSame(1755522896, $topup->expiredAt);

        self::assertCount(1, $captured);
        /** @var array{request: RequestInterface} $entry */
        $entry = $captured[0];
        $req = $entry['request'];

        self::assertSame('POST', $req->getMethod());
        self::assertSame('/v1/credits/topup', $req->getUri()->getPath());
        self::assertSame('M', $req->getHeaderLine('Merchant'));
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));

        // Unset optional urls are dropped entirely - not sent as "" - so the signed
        // canonical body contains only the required fields.
        $body = (string) $req->getBody();
        self::assertSame('{"amount":"250.00","currency":"USDT"}', $body);
        self::assertSame(Sign::sign($body, 'K'), $req->getHeaderLine('Signature'));
    }

    public function testTopupSendsRedirectUrlsAndMapsResponseWithoutOptionals(): void
    {
        /** @var RequestInterface[] $captured */
        $captured = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'invoice_id' => 1,
                'payment_link' => 'https://pay.crypto-chief.com/topup/def456',
                'amount' => '10.00',
                'currency' => 'USDC',
                'status' => 'pending',
            ]) ?: ''),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($captured));
        $http = new GuzzleClient(['handler' => $stack]);

        $client = new Client(
            merchantId: 'M',
            apiKey: 'K',
            httpClient: $http,
        );

        $topup = $client->credits()->topup(new CreditsTopupRequest(
            amount: '10.00',
            currency: 'USDC',
            urlSuccess: 'https://example.com/ok',
            urlError: 'https://example.com/fail',
        ));

        self::assertSame(1, $topup->invoiceId);
        self::assertSame('https://pay.crypto-chief.com/topup/def456', $topup->paymentLink);
        self::assertSame('10.00', $topup->amount);
        self::assertSame('USDC', $topup->currency);
        self::assertSame('pending', $topup->status);
        self::assertNull($topup->orderUuid);
        self::assertNull($topup->expiredAt);

        self::assertCount(1, $captured);
        /** @var array{request: RequestInterface} $entry */
        $entry = $captured[0];
        $req = $entry['request'];

        $body = (string) $req->getBody();
        self::assertSame(
            '{"amount":"10.00","currency":"USDC",'
            . '"url_error":"https://example.com/fail",'
            . '"url_success":"https://example.com/ok"}',
            $body
        );
        self::assertSame(Sign::sign($body, 'K'), $req->getHeaderLine('Signature'));
    }
}
