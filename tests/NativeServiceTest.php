<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Client;
use CryptoChief\Processing\Dto\NativeBuyRequest;
use CryptoChief\Processing\Dto\NativeOrder;
use CryptoChief\Processing\Dto\NativeQuoteRequest;
use CryptoChief\Processing\Exception\ApiException;
use CryptoChief\Processing\Exception\CryptoChiefException;
use CryptoChief\Processing\Tests\Support\JsonBody;
use CryptoChief\Processing\Tests\Support\SignedRequest;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class NativeServiceTest extends TestCase
{
    private const T_ADDR = 'TReceiverAddress000000000000000000001';

    /**
     * @param Response[] $responses
     * @param array<int, mixed> $captured
     */
    private function client(array $responses, array &$captured, int $retries = 3): Client
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($captured));

        return new Client(
            merchantId: 'M',
            apiKey: 'K',
            retries: $retries,
            httpClient: new GuzzleClient(['handler' => $stack]),
        );
    }

    public function testQuoteMapsFullResponse(): void
    {
        /** @var RequestInterface[] $captured */
        $captured = [];
        $client = $this->client([new Response(200, [], json_encode([
            'ref' => 'nq_9f2e1c7a',
            'network' => 'TRON_MAINNET',
            'receive_address' => self::T_ADDR,
            'amount' => '25.5',
            'coin_price_usd' => '7.80',
            'transfer_fee' => '1.100000',
            'transfer_fee_usd' => '0.34',
            'subtotal_usd' => '8.14',
            'total_usd' => '10.58',
            'credits' => 105800000,
            'coin_usd' => '0.30590000',
            'expires_at' => '2026-09-18T12:46:30Z',
            'expires_in_sec' => 90,
        ]) ?: '')], $captured);

        $quote = $client->native()->quote(new NativeQuoteRequest(
            network: 'TRON_MAINNET',
            receiveAddress: self::T_ADDR,
            amount: '25.5',
        ));

        self::assertSame('nq_9f2e1c7a', $quote->ref);
        self::assertSame('TRON_MAINNET', $quote->network);
        self::assertSame(self::T_ADDR, $quote->receiveAddress);
        self::assertSame('25.5', $quote->amount);
        self::assertSame('7.80', $quote->coinPriceUsd);
        self::assertSame('1.100000', $quote->transferFee);
        self::assertSame('0.34', $quote->transferFeeUsd);
        self::assertSame('8.14', $quote->subtotalUsd);
        self::assertSame('10.58', $quote->totalUsd);
        self::assertSame(105800000, $quote->credits);
        self::assertSame('0.30590000', $quote->coinUsd);
        self::assertSame('2026-09-18T12:46:30Z', $quote->expiresAt);
        self::assertSame(90, $quote->expiresInSec);

        self::assertCount(1, $captured);
        /** @var array{request: RequestInterface} $entry */
        $entry = $captured[0];
        $req = $entry['request'];

        self::assertSame('POST', $req->getMethod());
        self::assertSame('/v1/native/quote', $req->getUri()->getPath());
        self::assertSame('M', $req->getHeaderLine('Merchant'));
        self::assertFalse($req->hasHeader('Idempotency-Key'));

        $body = (string) $req->getBody();
        JsonBody::assertSameValue(
            '{"network":"TRON_MAINNET","receive_address":"' . self::T_ADDR . '","amount":"25.5"}',
            $body
        );
        SignedRequest::assertSignedV1($req);
    }

    public function testBuySendsTheIdempotencyKeyHeaderAndMapsADeliveredOrder(): void
    {
        /** @var RequestInterface[] $captured */
        $captured = [];
        $client = $this->client([new Response(200, [], json_encode([
            'id' => 90210,
            'idempotency_key' => 'native-2026-09-18-0001',
            'status' => 'delivered',
            'network' => 'TRON_MAINNET',
            'receive_address' => self::T_ADDR,
            'amount' => '25.5',
            'tx_hash' => 'a1b2c3d4e5f6',
            'transfer_fee' => '1.100000',
            'transfer_fee_usd' => '0.34',
            'coin_price_usd' => '7.80',
            'total_usd' => '10.58',
            'credits' => 105800000,
            'coin_usd' => '0.30590000',
            'settled' => true,
            'needs_attention' => false,
            'created_at' => '2026-09-18T12:34:56Z',
            'delivered_at' => '2026-09-18T12:35:04Z',
        ]) ?: '')], $captured);

        $order = $client->native()->buy(new NativeBuyRequest(
            network: 'TRON_MAINNET',
            receiveAddress: self::T_ADDR,
            amount: '25.5',
        ), 'native-2026-09-18-0001');

        self::assertSame(90210, $order->id);
        self::assertSame('native-2026-09-18-0001', $order->idempotencyKey);
        self::assertSame(NativeOrder::STATUS_DELIVERED, $order->status);
        self::assertSame('TRON_MAINNET', $order->network);
        self::assertSame(self::T_ADDR, $order->receiveAddress);
        self::assertSame('25.5', $order->amount);
        self::assertSame('a1b2c3d4e5f6', $order->txHash);
        self::assertSame('1.100000', $order->transferFee);
        self::assertSame('0.34', $order->transferFeeUsd);
        self::assertSame('7.80', $order->coinPriceUsd);
        self::assertSame('10.58', $order->totalUsd);
        self::assertSame(105800000, $order->credits);
        self::assertSame('0.30590000', $order->coinUsd);
        self::assertTrue($order->settled);
        self::assertFalse($order->needsAttention);
        self::assertNull($order->errorCode);
        self::assertNull($order->error);
        self::assertSame('2026-09-18T12:34:56Z', $order->createdAt);
        self::assertSame('2026-09-18T12:35:04Z', $order->deliveredAt);

        self::assertCount(1, $captured);
        /** @var array{request: RequestInterface} $entry */
        $entry = $captured[0];
        $req = $entry['request'];

        self::assertSame('POST', $req->getMethod());
        self::assertSame('/v1/native/buy', $req->getUri()->getPath());
        self::assertSame('native-2026-09-18-0001', $req->getHeaderLine('Idempotency-Key'));

        // The key goes in the header only - it is not part of the body.
        $body = (string) $req->getBody();
        JsonBody::assertSameValue(
            '{"network":"TRON_MAINNET","receive_address":"' . self::T_ADDR . '","amount":"25.5"}',
            $body
        );
        // ...and it is covered by the signature.
        SignedRequest::assertSignedV1($req);
    }

    public function testBuyRefusedReturnsTheOrderWithNoCharge(): void
    {
        $captured = [];
        // 502 retries are pointless here - the order is settled. retries: 0.
        $client = $this->client([new Response(502, [], json_encode([
            'id' => 90211,
            'idempotency_key' => 'native-2026-09-18-0002',
            'status' => 'refused',
            'network' => 'TRON_MAINNET',
            'receive_address' => self::T_ADDR,
            'amount' => '25.5',
            'settled' => true,
            'needs_attention' => false,
            'error_code' => 'INSUFFICIENT_LIQUIDITY',
            'error' => 'not enough liquidity to fill this order',
            'created_at' => '2026-09-18T12:34:56Z',
        ]) ?: '')], $captured, retries: 0);

        $order = $client->native()->buy(
            new NativeBuyRequest(network: 'TRON_MAINNET', receiveAddress: self::T_ADDR, amount: '25.5'),
            'native-2026-09-18-0002',
        );

        self::assertSame(NativeOrder::STATUS_REFUSED, $order->status);
        self::assertTrue($order->settled);
        self::assertFalse($order->needsAttention);
        self::assertSame('INSUFFICIENT_LIQUIDITY', $order->errorCode);
        self::assertSame('not enough liquidity to fill this order', $order->error);

        // Nothing was sent or charged: the tx and the price fields are absent from the
        // JSON, and absent means null - a zero would read as "this was free".
        self::assertNull($order->txHash);
        self::assertNull($order->transferFee);
        self::assertNull($order->transferFeeUsd);
        self::assertNull($order->coinPriceUsd);
        self::assertNull($order->totalUsd);
        self::assertNull($order->credits);
        self::assertNull($order->coinUsd);
        self::assertNull($order->deliveredAt);
    }

    public function testBuyUnresolvedReturnsTheOrderNeedingAttention(): void
    {
        $captured = [];
        $client = $this->client([new Response(409, [], json_encode([
            'id' => 90212,
            'idempotency_key' => 'native-2026-09-18-0003',
            'status' => 'unresolved',
            'network' => 'TRON_MAINNET',
            'receive_address' => self::T_ADDR,
            'amount' => '25.5',
            'transfer_fee' => '1.100000',
            'transfer_fee_usd' => '0.34',
            'coin_price_usd' => '7.80',
            'total_usd' => '10.58',
            'credits' => 105800000,
            'coin_usd' => '0.30590000',
            'settled' => false,
            'needs_attention' => true,
            'error_code' => 'SEND_UNKNOWN',
            'error' => 'the transfer outcome never arrived',
            'created_at' => '2026-09-18T12:34:56Z',
        ]) ?: '')], $captured, retries: 0);

        // 409 + needs_attention: do NOT retry; follow the order with order().
        $order = $client->native()->buy(
            new NativeBuyRequest(quoteRef: 'nq_9f2e1c7a'),
            'native-2026-09-18-0003',
        );

        self::assertSame(NativeOrder::STATUS_UNRESOLVED, $order->status);
        self::assertFalse($order->settled);
        self::assertTrue($order->needsAttention);
        self::assertSame('SEND_UNKNOWN', $order->errorCode);
        self::assertSame('the transfer outcome never arrived', $order->error);
        // Charged, because the coins may already be sent.
        self::assertSame(105800000, $order->credits);
    }

    public function testBuyRefusedForInsufficientCreditsAnswers402WithTheOrder(): void
    {
        $captured = [];
        // A refusal the customer can fix answers 402 - still with the order as the body.
        $client = $this->client([new Response(402, [], json_encode([
            'id' => 90213,
            'idempotency_key' => 'native-2026-09-18-0004',
            'status' => 'refused',
            'network' => 'TRON_MAINNET',
            'receive_address' => self::T_ADDR,
            'amount' => '25.5',
            'settled' => true,
            'needs_attention' => false,
            'error_code' => 'INSUFFICIENT_CREDITS',
            'error' => 'your credit balance did not cover this order; nothing was bought and nothing was charged',
            'created_at' => '2026-09-18T12:34:56Z',
        ]) ?: '')], $captured);

        $order = $client->native()->buy(
            new NativeBuyRequest(network: 'TRON_MAINNET', receiveAddress: self::T_ADDR, amount: '25.5'),
            'native-2026-09-18-0004',
        );

        self::assertSame(NativeOrder::STATUS_REFUSED, $order->status);
        self::assertSame('INSUFFICIENT_CREDITS', $order->errorCode);
        self::assertTrue($order->settled);
        self::assertFalse($order->needsAttention);
        self::assertNull($order->credits);
        self::assertNull($order->totalUsd);
    }

    public function testBuyQuoteExpiredIsAnErrorEnvelopeNotAnOrder(): void
    {
        $captured = [];
        // A 409 error envelope has no id + status - the guard keeps it from being
        // mistaken for an order, so it surfaces as a regular ApiException.
        $client = $this->client([new Response(409, [], json_encode([
            'ok' => false,
            'error' => 'QUOTE_EXPIRED',
            'msg' => 'that quote has expired; ask for a new price',
        ]) ?: '')], $captured);

        try {
            $client->native()->buy(new NativeBuyRequest(quoteRef: 'nq_9f2e1c7a'), 'native-2026-09-18-0005');
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('QUOTE_EXPIRED', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
    }

    public function testBuyWithoutAnIdempotencyKeyThrowsLocally(): void
    {
        /** @var RequestInterface[] $captured */
        $captured = [];
        $client = $this->client([new Response(200, [], '{}')], $captured);

        try {
            $client->native()->buy(
                new NativeBuyRequest(network: 'TRON_MAINNET', receiveAddress: self::T_ADDR, amount: '25.5'),
                '',
            );
            self::fail('expected CryptoChiefException');
        } catch (CryptoChiefException $e) {
            self::assertStringContainsString('idempotency key is required', $e->getMessage());
        }

        // Refused before any request went out: a keyless call would buy the coins
        // twice on a retry.
        self::assertCount(0, $captured);
    }

    public function testOrderFetchesByIdempotencyKey(): void
    {
        /** @var RequestInterface[] $captured */
        $captured = [];
        $client = $this->client([new Response(200, [], json_encode([
            'id' => 90210,
            'idempotency_key' => 'native-2026-09-18-0001',
            'status' => 'delivered',
            'network' => 'TRON_MAINNET',
            'receive_address' => self::T_ADDR,
            'amount' => '25.5',
            'tx_hash' => 'a1b2c3d4e5f6',
            'transfer_fee' => '1.100000',
            'transfer_fee_usd' => '0.34',
            'coin_price_usd' => '7.80',
            'total_usd' => '10.58',
            'credits' => 105800000,
            'coin_usd' => '0.30590000',
            'settled' => true,
            'needs_attention' => false,
            'created_at' => '2026-09-18T12:34:56Z',
            'delivered_at' => '2026-09-18T12:35:04Z',
        ]) ?: '')], $captured);

        $order = $client->native()->order('native-2026-09-18-0001');

        self::assertSame(90210, $order->id);
        self::assertSame('native-2026-09-18-0001', $order->idempotencyKey);
        self::assertSame(NativeOrder::STATUS_DELIVERED, $order->status);
        self::assertSame('a1b2c3d4e5f6', $order->txHash);
        self::assertTrue($order->settled);

        self::assertCount(1, $captured);
        /** @var array{request: RequestInterface} $entry */
        $entry = $captured[0];
        $req = $entry['request'];

        self::assertSame('POST', $req->getMethod());
        self::assertSame('/v1/native/order', $req->getUri()->getPath());

        $body = (string) $req->getBody();
        JsonBody::assertSameValue('{"key":"native-2026-09-18-0001"}', $body);
        SignedRequest::assertSignedV1($req);
    }
}
