<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Client;
use CryptoChief\Processing\Dto\EstimateTransactionRequest;
use CryptoChief\Processing\Exception\ApiException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class TransactionsServiceTest extends TestCase
{
    private const EVM_A = '0x1111111111111111111111111111111111111111';
    private const EVM_B = '0x2222222222222222222222222222222222222222';
    private const USDT = '0xdAC17F958D2ee523a2206206994597C13D831ec7';

    /**
     * @param Response[] $responses
     * @param array<int, mixed> $captured
     */
    private function client(array $responses, array &$captured): Client
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($captured));

        return new Client(
            merchantId: 'M',
            apiKey: 'K',
            httpClient: new GuzzleClient(['handler' => $stack]),
        );
    }

    /**
     * @param array<int, mixed> $captured
     * @return array<string, mixed>
     */
    private function sentBody(array $captured): array
    {
        /** @var array{request: RequestInterface} $entry */
        $entry = $captured[0];
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $entry['request']->getBody(), true);

        return $body;
    }

    public function testEstimateNativeReturnsFeeAndRequired(): void
    {
        $captured = [];
        $client = $this->client([new Response(200, [], json_encode([
            'network' => 'ETH_MAINNET',
            'chain_family' => 'EVM',
            'type' => 'native',
            'from_address' => self::EVM_A,
            'to_address' => self::EVM_B,
            'estimated_fee' => '0.00042',
            'estimated_fee_fiat' => '1.05',
            'required' => '0.01042',
            'required_fiat' => '26.05',
        ]) ?: '')], $captured);

        $out = $client->transactions()->estimate(new EstimateTransactionRequest(
            network: 'ETH_MAINNET',
            fromAddress: self::EVM_A,
            toAddress: self::EVM_B,
            value: '10000000000000000', // 0.01 ETH in wei
        ));

        /** @var array{request: RequestInterface} $entry */
        $entry = $captured[0];
        self::assertStringEndsWith('/v1/transaction/estimate', (string) $entry['request']->getUri());

        $body = $this->sentBody($captured);
        self::assertSame('ETH_MAINNET', $body['network']);
        self::assertSame(self::EVM_A, $body['from_address']);
        self::assertSame('native', $body['type']);
        self::assertSame(self::EVM_B, $body['to_address']);
        self::assertSame('10000000000000000', $body['value']);

        // Native: `required` is fee + value, everything the sender's balance has to cover.
        self::assertSame('EVM', $out->chainFamily);
        self::assertSame('native', $out->type);
        self::assertSame('0.00042', $out->estimatedFee);
        self::assertSame('1.05', $out->estimatedFeeFiat);
        self::assertSame('0.01042', $out->required);
        self::assertSame('26.05', $out->requiredFiat);
    }

    public function testEstimateTokenSendsTheContractAndReadsEmptyFiat(): void
    {
        $captured = [];
        $client = $this->client([new Response(200, [], json_encode([
            'network' => 'ETH_MAINNET',
            'chain_family' => 'EVM',
            'type' => 'token',
            'from_address' => self::EVM_A,
            'to_address' => self::EVM_B,
            'estimated_fee' => '0.00063',
            'estimated_fee_fiat' => '',
            'required' => '0.00063',
            'required_fiat' => '',
        ]) ?: '')], $captured);

        $out = $client->transactions()->estimate(new EstimateTransactionRequest(
            network: 'ETH_MAINNET',
            fromAddress: self::EVM_A,
            type: 'token',
            toAddress: self::EVM_B,
            value: '1500000', // 1.5 USDT in base units
            contract: self::USDT,
        ));

        $body = $this->sentBody($captured);
        self::assertSame('token', $body['type']);
        self::assertSame(self::USDT, $body['contract']);
        // Estimation has no webhook - url_callback is not part of this request shape.
        self::assertArrayNotHasKey('url_callback', $body);

        // Token: `required` is the fee only - the token amount comes off the token balance.
        self::assertSame('0.00063', $out->estimatedFee);
        self::assertSame('0.00063', $out->required);
        // No rate available: the fiat mirrors arrive as empty strings, not null.
        self::assertSame('', $out->estimatedFeeFiat);
        self::assertSame('', $out->requiredFiat);
    }

    public function testEstimateTronReturnsTheFeeBreakdown(): void
    {
        $captured = [];
        $client = $this->client([new Response(200, [], json_encode([
            'network' => 'TRON_MAINNET',
            'chain_family' => 'TRON',
            'type' => 'native',
            'from_address' => 'TFromAddress000000000000000000000001',
            'to_address' => 'TToAddress00000000000000000000000002',
            'estimated_fee' => '14.195',
            'estimated_fee_fiat' => '',
            'required' => '114.195',
            'required_fiat' => '',
            'fee_expected' => '1.195',
            'fee_limit' => '150',
            'energy' => 65000,
            'energy_fee' => '13',
            'bandwidth_fee' => '1',
            'activation_fee' => '0.195',
        ]) ?: '')], $captured);

        $out = $client->transactions()->estimate(new EstimateTransactionRequest(
            network: 'TRON_MAINNET',
            fromAddress: 'TFromAddress000000000000000000000001',
            toAddress: 'TToAddress00000000000000000000000002',
            value: '100000000', // 100 TRX in sun
        ));

        self::assertSame('TRON', $out->chainFamily);
        self::assertSame('14.195', $out->estimatedFee);
        self::assertSame('114.195', $out->required);

        // The TRON breakdown rides along. energy_fee + bandwidth_fee + activation_fee
        // is the gross burn: 13 + 1 + 0.195 = 14.195 = estimatedFee.
        self::assertSame('1.195', $out->feeExpected);
        self::assertSame('150', $out->feeLimit);
        self::assertSame(65000, $out->energy);
        self::assertSame('13', $out->energyFee);
        self::assertSame('1', $out->bandwidthFee);
        self::assertSame('0.195', $out->activationFee);
    }

    public function testEstimateNonTronLeavesTheBreakdownNull(): void
    {
        $captured = [];
        $client = $this->client([new Response(200, [], json_encode([
            'network' => 'ETH_MAINNET',
            'chain_family' => 'EVM',
            'type' => 'native',
            'from_address' => self::EVM_A,
            'to_address' => self::EVM_B,
            'estimated_fee' => '0.00042',
            'estimated_fee_fiat' => '1.05',
            'required' => '0.01042',
            'required_fiat' => '26.05',
        ]) ?: '')], $captured);

        $out = $client->transactions()->estimate(new EstimateTransactionRequest(
            network: 'ETH_MAINNET',
            fromAddress: self::EVM_A,
            toAddress: self::EVM_B,
            value: '10000000000000000',
        ));

        // The breakdown keys are absent from the JSON on non-TRON families; the SDK
        // surfaces that as null, never as a zero value.
        self::assertNull($out->feeExpected);
        self::assertNull($out->feeLimit);
        self::assertNull($out->energy);
        self::assertNull($out->energyFee);
        self::assertNull($out->bandwidthFee);
        self::assertNull($out->activationFee);
    }

    public function testEstimateContractCallPropagatesTheApiError(): void
    {
        $captured = [];
        // Relayed through the gateway: error is SERVICE_ERROR, the machine code is in msg.
        $client = $this->client([new Response(400, [], json_encode([
            'ok' => false,
            'error' => 'SERVICE_ERROR',
            'msg' => 'CONTRACT_ESTIMATE_UNSUPPORTED',
        ]) ?: '')], $captured);

        try {
            $client->transactions()->estimate(new EstimateTransactionRequest(
                network: 'ETH_MAINNET',
                fromAddress: self::EVM_A,
                type: 'contract',
                toAddress: self::EVM_B,
                value: '0',
            ));
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('CONTRACT_ESTIMATE_UNSUPPORTED', $e->errorCode);
            self::assertSame(400, $e->httpStatus);
            self::assertStringContainsString('CONTRACT_ESTIMATE_UNSUPPORTED', $e->getMessage());
        }
    }
}
