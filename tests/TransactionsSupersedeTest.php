<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Client;
use CryptoChief\Processing\Dto\ExecuteTransactionRequest;
use CryptoChief\Processing\Dto\SignTransactionRequest;
use CryptoChief\Processing\Dto\TransactionInfo;
use CryptoChief\Processing\ErrorCode;
use CryptoChief\Processing\Exception\ApiException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * EVM signature supersede: the `cancelled` status, `superseded_uuids` on the sign answer,
 * `error_reason` on the transaction, and the new error codes.
 */
final class TransactionsSupersedeTest extends TestCase
{
    private const OLD = '0c1d9f3e-5a7b-4c2e-9f1a-3b6d8e2f4a10';
    private const NEW = 'b4ee6a7a-f7c2-474d-b002-e83ebe3e78db';

    /**
     * @param Response[] $responses
     * @param array<int, mixed> $captured
     */
    private function client(array $responses, array &$captured): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($captured));

        return new Client(
            merchantId: 'M',
            apiKey: 'K',
            httpClient: new GuzzleClient(['handler' => $stack]),
            retries: 0,
        );
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body) ?: '');
    }

    private static function signReq(): SignTransactionRequest
    {
        return new SignTransactionRequest(
            network: 'ETH_MAINNET',
            fromAddress: '0xfrom',
            type: 'native',
            toAddress: '0xto',
            value: '1',
        );
    }

    public function testCancelledIsFinal(): void
    {
        self::assertTrue((new TransactionInfo(status: 'cancelled'))->isTerminal());
        foreach (['signed', 'broadcasting', 'broadcasted'] as $live) {
            self::assertFalse((new TransactionInfo(status: $live))->isTerminal(), $live);
        }
    }

    public function testWaitForReturnsASupersededSignatureAtOnce(): void
    {
        $captured = [];
        $body = ['uuid' => self::OLD, 'status' => 'cancelled', 'error_reason' => 'SUPERSEDED_BY:' . self::NEW];
        $client = $this->client(array_fill(0, 50, self::json(200, $body)), $captured);

        $tx = $client->transactions()->waitFor(self::OLD, 0.01, 0.3);

        self::assertSame('cancelled', $tx->status);
        self::assertSame('SUPERSEDED_BY:' . self::NEW, $tx->errorReason);
        self::assertCount(1, $captured);
    }

    public function testSignReadsTheReplacedSignatures(): void
    {
        $captured = [];
        $client = $this->client([self::json(200, [
            'uuid' => self::NEW,
            'status' => 'signed',
            'network' => 'ETH_MAINNET',
            'chain_family' => 'EVM',
            'signed_tx_hex' => '0x02',
            'tx_hash' => '0xabc',
            'expires_at' => '2026-06-01T12:10:00Z',
            'superseded_uuids' => [self::OLD],
        ])], $captured);

        $res = $client->transactions()->sign(self::signReq());

        self::assertSame([self::OLD], $res->supersededUuids);
    }

    public function testSignWithoutReplacedSignaturesReadsEmpty(): void
    {
        $captured = [];
        $client = $this->client([self::json(200, ['uuid' => self::NEW, 'status' => 'signed'])], $captured);

        self::assertSame([], $client->transactions()->sign(self::signReq())->supersededUuids);
    }

    public function testInfoReadsTheNonceGapReason(): void
    {
        $captured = [];
        $reason = 'NONCE_GAP: missing_nonce=7 blocking_uuid=' . self::OLD;
        $client = $this->client([self::json(200, [
            'uuid' => self::NEW,
            'status' => 'signed',
            'error_reason' => $reason,
        ])], $captured);

        $tx = $client->transactions()->info(self::NEW);

        self::assertSame($reason, $tx->errorReason);
        self::assertNull($tx->error);
    }

    /** @return array<string, array{ErrorCode}> */
    public static function nonceCodes(): array
    {
        return [
            'gap' => [ErrorCode::NonceGap],
            'used' => [ErrorCode::NonceAlreadyUsed],
        ];
    }

    #[DataProvider('nonceCodes')]
    public function testExecuteSurfacesTheNonceCodes(ErrorCode $code): void
    {
        $captured = [];
        $client = $this->client([self::json(400, [
            'error' => 'SERVICE_ERROR',
            'msg' => $code->value,
            'ok' => false,
        ])], $captured);

        try {
            $client->transactions()->execute(new ExecuteTransactionRequest(uuid: self::NEW));
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame($code->value, $e->errorCode);
            self::assertSame(400, $e->httpStatus);
        }
    }

    public function testSignIsRefusedWhileAnExecuteIsUnresolved(): void
    {
        $captured = [];
        $client = $this->client([self::json(400, [
            'error' => 'SERVICE_ERROR',
            'msg' => 'PREVIOUS_EXECUTE_UNRESOLVED: uuid=' . self::OLD,
            'ok' => false,
        ])], $captured);

        try {
            $client->transactions()->sign(self::signReq());
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertStringStartsWith(ErrorCode::PreviousExecuteUnresolved->value, $e->errorCode);
            self::assertStringEndsWith(self::OLD, $e->errorCode);
        }
    }
}
