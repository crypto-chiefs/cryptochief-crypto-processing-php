<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Client;
use CryptoChief\Processing\Dto\EnergyQuoteRequest;
use CryptoChief\Processing\Dto\EnergyRentRequest;
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

final class EnergyServiceTest extends TestCase
{
    private const T_ADDR = 'TSenderAddress0000000000000000000001';

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
            'ref' => 'q_9f2e1c7a',
            'receive_address' => self::T_ADDR,
            'energy' => 65000,
            'duration_sec' => 3600,
            'price_sun' => 4577000,
            'price_trx' => '4.577000',
            'price_usd' => '1.40',
            'credits' => 14000000,
            'trx_usd' => '0.30590000',
            'recipient_state' => 'token_missing',
            'burn_price_sun' => 14195000,
            'burn_price_trx' => '14.195000',
            'burn_price_usd' => '4.34',
            'burn_price_credits' => 43400000,
            'saving_trx' => '9.618000',
            'saving_usd' => '2.94',
            'saving_credits' => 29400000,
            'expires_at' => '2026-09-18T12:45:00Z',
            'expires_in_sec' => 600,
        ]) ?: '')], $captured);

        $quote = $client->energy()->quote(new EnergyQuoteRequest(
            receiveAddress: self::T_ADDR,
            energy: 65000,
            durationSec: 3600,
        ));

        self::assertSame('q_9f2e1c7a', $quote->ref);
        self::assertSame(self::T_ADDR, $quote->receiveAddress);
        self::assertSame(65000, $quote->energy);
        self::assertSame(3600, $quote->durationSec);
        self::assertSame(4577000, $quote->priceSun);
        self::assertSame('4.577000', $quote->priceTrx);
        self::assertSame('1.40', $quote->priceUsd);
        self::assertSame(14000000, $quote->credits);
        self::assertSame('0.30590000', $quote->trxUsd);
        self::assertSame('token_missing', $quote->recipientState);
        self::assertSame(14195000, $quote->burnPriceSun);
        self::assertSame('14.195000', $quote->burnPriceTrx);
        self::assertSame('4.34', $quote->burnPriceUsd);
        self::assertSame(43400000, $quote->burnPriceCredits);
        self::assertSame('9.618000', $quote->savingTrx);
        self::assertSame('2.94', $quote->savingUsd);
        self::assertSame(29400000, $quote->savingCredits);
        self::assertSame('2026-09-18T12:45:00Z', $quote->expiresAt);
        self::assertSame(600, $quote->expiresInSec);

        self::assertCount(1, $captured);
        /** @var array{request: RequestInterface} $entry */
        $entry = $captured[0];
        $req = $entry['request'];

        self::assertSame('POST', $req->getMethod());
        self::assertSame('/v1/energy/quote', $req->getUri()->getPath());
        self::assertSame('M', $req->getHeaderLine('Merchant'));
        self::assertFalse($req->hasHeader('Idempotency-Key'));

        $body = (string) $req->getBody();
        JsonBody::assertSameValue(
            '{"receive_address":"' . self::T_ADDR . '","energy":65000,"duration_sec":3600}',
            $body
        );
        SignedRequest::assertSignedV1($req);
    }

    public function testQuoteWithoutRateLeavesTheDollarAndCreditsFieldsNull(): void
    {
        $captured = [];
        $client = $this->client([new Response(200, [], json_encode([
            'ref' => 'q_9f2e1c7a',
            'receive_address' => self::T_ADDR,
            'energy' => 65000,
            'duration_sec' => 3600,
            'price_sun' => 4577000,
            'price_trx' => '4.577000',
            'recipient_state' => 'token_missing',
            'burn_price_sun' => 14195000,
            'burn_price_trx' => '14.195000',
            'saving_trx' => '9.618000',
            'expires_at' => '2026-09-18T12:45:00Z',
            'expires_in_sec' => 600,
        ]) ?: '')], $captured);

        $quote = $client->energy()->quote(new EnergyQuoteRequest(receiveAddress: self::T_ADDR));

        self::assertSame(4577000, $quote->priceSun);
        // No rate on the server: the conversions are omitted, read back as null -
        // never as a guessed zero.
        self::assertNull($quote->priceUsd);
        self::assertNull($quote->credits);
        self::assertNull($quote->trxUsd);
        self::assertNull($quote->burnPriceUsd);
        self::assertNull($quote->burnPriceCredits);
        self::assertNull($quote->savingUsd);
        self::assertNull($quote->savingCredits);
    }

    public function testRentSendsTheIdempotencyKeyHeaderAndMapsADeliveredOrder(): void
    {
        /** @var RequestInterface[] $captured */
        $captured = [];
        $client = $this->client([new Response(200, [], json_encode([
            'id' => 90210,
            'idempotency_key' => 'energy-2026-09-18-0001',
            'status' => 'delivered',
            'receive_address' => self::T_ADDR,
            'energy' => 65000,
            'duration_sec' => 3600,
            'price_sun' => 4577000,
            'price_trx' => '4.577000',
            'price_usd' => '1.40',
            'credits' => 14000000,
            'trx_usd' => '0.30590000',
            'delivered_energy' => 65000,
            'settled' => true,
            'needs_attention' => false,
            'created_at' => '2026-09-18T12:34:56Z',
            'delivered_at' => '2026-09-18T12:35:04Z',
        ]) ?: '')], $captured);

        $order = $client->energy()->rent(new EnergyRentRequest(
            receiveAddress: self::T_ADDR,
            energy: 65000,
            durationSec: 3600,
        ), 'energy-2026-09-18-0001');

        self::assertSame(90210, $order->id);
        self::assertSame('energy-2026-09-18-0001', $order->idempotencyKey);
        self::assertSame('delivered', $order->status);
        self::assertSame(self::T_ADDR, $order->receiveAddress);
        self::assertSame(65000, $order->energy);
        self::assertSame(3600, $order->durationSec);
        self::assertSame(4577000, $order->priceSun);
        self::assertSame('4.577000', $order->priceTrx);
        self::assertSame('1.40', $order->priceUsd);
        self::assertSame(14000000, $order->credits);
        self::assertSame('0.30590000', $order->trxUsd);
        self::assertSame(65000, $order->deliveredEnergy);
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
        self::assertSame('/v1/energy/rent', $req->getUri()->getPath());
        self::assertSame('energy-2026-09-18-0001', $req->getHeaderLine('Idempotency-Key'));

        // The key goes in the header only - it is not part of the body.
        $body = (string) $req->getBody();
        JsonBody::assertSameValue(
            '{"receive_address":"' . self::T_ADDR . '","energy":65000,"duration_sec":3600}',
            $body
        );
        // ...and it is covered by the signature.
        SignedRequest::assertSignedV1($req);
    }

    public function testRentRefusedReturnsTheOrderWithNoCharge(): void
    {
        $captured = [];
        // 502 retries are pointless here - the order is settled. retries: 0.
        $client = $this->client([new Response(502, [], json_encode([
            'id' => 90211,
            'idempotency_key' => 'energy-2026-09-18-0002',
            'status' => 'refused',
            'receive_address' => self::T_ADDR,
            'energy' => 65000,
            'duration_sec' => 3600,
            'price_sun' => 4577000,
            'price_trx' => '4.577000',
            'settled' => true,
            'needs_attention' => false,
            'error_code' => 'SUPPLIER_REFUSED',
            'error' => 'no supplier could fill this order',
            'created_at' => '2026-09-18T12:34:56Z',
        ]) ?: '')], $captured, retries: 0);

        $order = $client->energy()->rent(
            new EnergyRentRequest(receiveAddress: self::T_ADDR),
            'energy-2026-09-18-0002',
        );

        self::assertSame('refused', $order->status);
        self::assertTrue($order->settled);
        self::assertFalse($order->needsAttention);
        self::assertSame('SUPPLIER_REFUSED', $order->errorCode);
        self::assertSame('no supplier could fill this order', $order->error);

        // Nothing was charged: price_usd / credits / trx_usd are absent from the
        // JSON, and absent means null - a zero would read as "this was free".
        self::assertNull($order->priceUsd);
        self::assertNull($order->credits);
        self::assertNull($order->trxUsd);
        self::assertNull($order->deliveredEnergy);
        self::assertNull($order->deliveredAt);
    }

    public function testRentUnresolvedReturnsTheOrderNeedingAttention(): void
    {
        $captured = [];
        $client = $this->client([new Response(409, [], json_encode([
            'id' => 90212,
            'idempotency_key' => 'energy-2026-09-18-0003',
            'status' => 'unresolved',
            'receive_address' => self::T_ADDR,
            'energy' => 65000,
            'duration_sec' => 3600,
            'price_sun' => 4577000,
            'price_trx' => '4.577000',
            'price_usd' => '1.40',
            'credits' => 14000000,
            'trx_usd' => '0.30590000',
            'settled' => false,
            'needs_attention' => true,
            'error_code' => 'SUPPLIER_UNKNOWN',
            'error' => 'supplier never answered',
            'created_at' => '2026-09-18T12:34:56Z',
        ]) ?: '')], $captured, retries: 0);

        // 409 + needs_attention: do NOT retry; follow the order with order().
        $order = $client->energy()->rent(
            new EnergyRentRequest(receiveAddress: self::T_ADDR),
            'energy-2026-09-18-0003',
        );

        self::assertSame('unresolved', $order->status);
        self::assertFalse($order->settled);
        self::assertTrue($order->needsAttention);
        self::assertSame('SUPPLIER_UNKNOWN', $order->errorCode);
        self::assertSame('supplier never answered', $order->error);
        // Charged, because the energy may already be delegated.
        self::assertSame(14000000, $order->credits);
    }

    public function testRentRefusedForInsufficientCreditsAnswers402WithTheOrder(): void
    {
        $captured = [];
        // A refusal the customer can fix answers 402 - still with the order as the body.
        $client = $this->client([new Response(402, [], json_encode([
            'id' => 90213,
            'idempotency_key' => 'energy-2026-09-18-0004',
            'status' => 'refused',
            'receive_address' => self::T_ADDR,
            'energy' => 65000,
            'duration_sec' => 3600,
            'price_sun' => 4577000,
            'price_trx' => '4.577000',
            'settled' => true,
            'needs_attention' => false,
            'error_code' => 'INSUFFICIENT_CREDITS',
            'error' => 'your credit balance did not cover this order; nothing was bought and nothing was charged',
            'created_at' => '2026-09-18T12:34:56Z',
        ]) ?: '')], $captured);

        $order = $client->energy()->rent(
            new EnergyRentRequest(receiveAddress: self::T_ADDR),
            'energy-2026-09-18-0004',
        );

        self::assertSame('refused', $order->status);
        self::assertSame('INSUFFICIENT_CREDITS', $order->errorCode);
        self::assertTrue($order->settled);
        self::assertFalse($order->needsAttention);
        self::assertNull($order->credits);
        self::assertNull($order->priceUsd);
    }

    public function testRentQuoteExpiredIsAnErrorEnvelopeNotAnOrder(): void
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
            $client->energy()->rent(new EnergyRentRequest(quoteRef: 'q_9f2e1c7a'), 'energy-2026-09-18-0005');
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('QUOTE_EXPIRED', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
    }

    public function testRentWithoutAnIdempotencyKeyThrowsLocally(): void
    {
        /** @var RequestInterface[] $captured */
        $captured = [];
        $client = $this->client([new Response(200, [], '{}')], $captured);

        try {
            $client->energy()->rent(new EnergyRentRequest(receiveAddress: self::T_ADDR), '');
            self::fail('expected CryptoChiefException');
        } catch (CryptoChiefException $e) {
            self::assertStringContainsString('idempotency key is required', $e->getMessage());
        }

        // Refused before any request went out: a keyless call would buy the energy
        // twice on a retry.
        self::assertCount(0, $captured);
    }

    public function testOrderFetchesByIdempotencyKey(): void
    {
        /** @var RequestInterface[] $captured */
        $captured = [];
        $client = $this->client([new Response(200, [], json_encode([
            'id' => 90210,
            'idempotency_key' => 'energy-2026-09-18-0001',
            'status' => 'delivered',
            'receive_address' => self::T_ADDR,
            'energy' => 65000,
            'duration_sec' => 3600,
            'price_sun' => 4577000,
            'price_trx' => '4.577000',
            'price_usd' => '1.40',
            'credits' => 14000000,
            'trx_usd' => '0.30590000',
            'delivered_energy' => 65000,
            'settled' => true,
            'needs_attention' => false,
            'created_at' => '2026-09-18T12:34:56Z',
            'delivered_at' => '2026-09-18T12:35:04Z',
        ]) ?: '')], $captured);

        $order = $client->energy()->order('energy-2026-09-18-0001');

        self::assertSame(90210, $order->id);
        self::assertSame('energy-2026-09-18-0001', $order->idempotencyKey);
        self::assertSame('delivered', $order->status);
        self::assertSame(65000, $order->deliveredEnergy);
        self::assertTrue($order->settled);

        self::assertCount(1, $captured);
        /** @var array{request: RequestInterface} $entry */
        $entry = $captured[0];
        $req = $entry['request'];

        self::assertSame('POST', $req->getMethod());
        self::assertSame('/v1/energy/order', $req->getUri()->getPath());

        $body = (string) $req->getBody();
        JsonBody::assertSameValue('{"key":"energy-2026-09-18-0001"}', $body);
        SignedRequest::assertSignedV1($req);
    }
}
