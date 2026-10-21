<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Client;
use CryptoChief\Processing\Dto\BatchItemResult;
use CryptoChief\Processing\Dto\BatchPayoutRequest;
use CryptoChief\Processing\Dto\BatchPayoutResponse;
use CryptoChief\Processing\Dto\ExecutePayoutRequest;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class PayoutsServiceTest extends TestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function batchEndpoints(): array
    {
        return [
            'estimate' => ['batchEstimate', '/v1/payout/batch/estimate', 'ok'],
            'execute' => ['batchExecute', '/v1/payout/batch/execute', 'queue'],
        ];
    }

    #[DataProvider('batchEndpoints')]
    public function testBatchReadsItemHttpStatus(string $method, string $path, string $successStatus): void
    {
        $success = ['index' => 0, 'status' => $successStatus];
        if ($method === 'batchEstimate') {
            $success['estimate'] = ['network' => 'ETH_MAINNET', 'amount_to_receive' => '0.01'];
        } else {
            $success['order_id'] = 'order-0';
            $success['uuid'] = 'payout-0';
            $success['payout'] = ['uuid' => 'payout-0', 'status' => 'queue'];
        }
        $body = [
            'total' => 5,
            'accepted' => 1,
            'rejected' => 4,
            'items' => [
                $success,
                ['index' => 1, 'status' => 'error', 'error' => 'FAILED_TO_ESTIMATE_FEE', 'http_status' => 400],
                ['index' => 2, 'status' => 'error', 'error' => 'FAILED_TO_ESTIMATE_FEE', 'http_status' => 502],
                ['index' => 3, 'status' => 'error', 'error' => 'INTERNAL_ERROR'],
                ['index' => 4, 'status' => 'error', 'error' => 'FUTURE_ERROR', 'http_status' => 599],
            ],
        ];
        $captured = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($captured));
        $client = new Client(
            merchantId: 'M',
            apiKey: 'K',
            httpClient: new GuzzleClient(['handler' => $stack]),
        );
        $requests = [];
        for ($index = 0; $index < 5; $index++) {
            $requests[] = new ExecutePayoutRequest(
                network: 'ETH_MAINNET',
                coin: 'ETH',
                amount: '0.01',
                toAddress: '0x1111111111111111111111111111111111111111',
                orderId: 'order-' . $index,
            );
        }

        $out = $client->payouts()->$method(new BatchPayoutRequest(items: $requests));

        self::assertCount(1, $captured);
        /** @var array{request: RequestInterface} $entry */
        $entry = $captured[0];
        self::assertStringEndsWith($path, (string) $entry['request']->getUri());
        self::assertNotNull($out->items);
        self::assertCount(5, $out->items);
        self::assertSame($successStatus, $out->items[0]->status);
        self::assertSame('FAILED_TO_ESTIMATE_FEE', $out->items[1]->error);
        self::assertSame('FAILED_TO_ESTIMATE_FEE', $out->items[2]->error);
        foreach ([null, 400, 502, null, 599] as $index => $httpStatus) {
            self::assertInstanceOf(BatchItemResult::class, $out->items[$index]);
            self::assertSame($httpStatus, $out->items[$index]->httpStatus);
        }
        self::assertFalse($out->items[0]->isRetryable());
        self::assertFalse($out->items[1]->isRetryable());
        self::assertTrue($out->items[2]->isRetryable());
        self::assertFalse($out->items[3]->isRetryable());
        self::assertFalse($out->items[4]->isRetryable());

        $wire = $out->toWire();
        self::assertArrayNotHasKey('http_status', $wire['items'][0]);
        self::assertSame(400, $wire['items'][1]['http_status']);
        self::assertSame(502, $wire['items'][2]['http_status']);
        self::assertArrayNotHasKey('http_status', $wire['items'][3]);
        self::assertSame(599, $wire['items'][4]['http_status']);
        $decoded = json_decode(json_encode($out, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($wire, BatchPayoutResponse::fromWire($decoded)->toWire());
    }

    /** @return array<string, array{?int, bool}> */
    public static function itemHttpStatuses(): array
    {
        return [
            'absent' => [null, false],
            'input error' => [400, false],
            'canceled' => [408, false],
            'internal error' => [500, false],
            'temporary failure' => [502, true],
            'unavailable' => [503, false],
            'timeout' => [504, false],
            'unknown' => [599, false],
        ];
    }

    #[DataProvider('itemHttpStatuses')]
    public function testBatchItemIsRetryableOnlyFor502(?int $httpStatus, bool $retryable): void
    {
        $item = new BatchItemResult(status: 'error', error: 'FAILED_TO_ESTIMATE_FEE', httpStatus: $httpStatus);

        self::assertSame($retryable, $item->isRetryable());
    }

    public function testBatchItemKeepsExistingPositionalArguments(): void
    {
        $item = new BatchItemResult(3, 'order-3', 'error', 'payout-3', 'INTERNAL_ERROR');

        self::assertSame(3, $item->index);
        self::assertSame('order-3', $item->orderId);
        self::assertSame('error', $item->status);
        self::assertSame('payout-3', $item->uuid);
        self::assertSame('INTERNAL_ERROR', $item->error);
        self::assertNull($item->httpStatus);
    }
}
