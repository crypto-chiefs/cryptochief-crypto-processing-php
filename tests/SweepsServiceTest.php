<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Clear;
use CryptoChief\Processing\Client;
use CryptoChief\Processing\Dto\CreatePayInRequest;
use CryptoChief\Processing\Environment;
use CryptoChief\Processing\SweepPolicyMode;
use CryptoChief\Processing\SweepStatus;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class SweepsServiceTest extends TestCase
{
    /**
     * @param array<string, mixed> $payload
     * @param array<int, mixed> $captured
     */
    private function client(array $payload, array &$captured): Client
    {
        $mock = new MockHandler([new Response(200, [], json_encode($payload) ?: '')]);
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

    /** @return array<string, mixed> */
    private function settingsPayload(): array
    {
        return [
            'wallet_address' => '0xabc',
            'network_code' => 'ETH_MAINNET',
            'effective' => [
                'type_work' => 'threshold',
                'threshold_amount_usd' => '250',
                'fee_mode' => 'mix',
                'source' => 'wallet',
            ],
            'override' => [
                'network_code' => '',
                'type_work' => 'threshold',
                'threshold_amount_usd' => '250',
                'fee_mode' => null,
                'source' => 'merchant',
                'locked' => false,
            ],
            'project_default' => ['type_work' => 'momentum', 'fee_mode' => 'client'],
        ];
    }

    public function testSettingsReturnsThreeDistinguishableLayers(): void
    {
        $captured = [];
        $client = $this->client($this->settingsPayload(), $captured);

        $out = $client->sweeps()->settings('0xabc');

        /** @var array{request: RequestInterface} $entry */
        $entry = $captured[0];
        self::assertStringEndsWith('/v1/sweeps/settings', (string) $entry['request']->getUri());

        self::assertNotNull($out->effective);
        self::assertSame(SweepPolicyMode::Threshold->value, $out->effective->typeWork);
        self::assertSame('250', $out->effective->thresholdAmountUsd);
        self::assertSame('wallet', $out->effective->source);

        // An inherited field reads as null on the override while the effective policy
        // still has a value. That difference is the point of the three-layer shape.
        self::assertNotNull($out->override);
        self::assertNull($out->override->feeMode);
        self::assertSame('threshold', $out->override->typeWork);

        self::assertNotNull($out->projectDefault);
        self::assertSame(SweepPolicyMode::Momentum->value, $out->projectDefault->typeWork);
    }

    public function testUpdateWritesOnlyTheFieldsItWasGiven(): void
    {
        $captured = [];
        $client = $this->client($this->settingsPayload(), $captured);

        $client->sweeps()->updateSettings(
            address: '0xabc',
            typeWork: SweepPolicyMode::Threshold,
            thresholdAmountUsd: '250',
        );

        $body = $this->sentBody($captured);
        self::assertSame('threshold', $body['type_work']);
        self::assertSame('250', $body['threshold_amount_usd']);
        // Sending fee_mode at all would rewrite it; untouched means absent.
        self::assertArrayNotHasKey('fee_mode', $body);
        self::assertSame(['type_work', 'threshold_amount_usd'], $body['fields']);
    }

    public function testClearNamesTheFieldAndSendsNoValue(): void
    {
        $captured = [];
        $client = $this->client($this->settingsPayload(), $captured);

        $client->sweeps()->updateSettings(address: '0xabc', typeWork: Clear::value());

        $body = $this->sentBody($captured);
        // The API's way of saying "inherit this again": named, with no value. null cannot
        // express it because it already means "not supplied".
        self::assertSame(['type_work'], $body['fields']);
        self::assertArrayNotHasKey('type_work', $body);
    }

    public function testHistoryTellsABroadcastSweepFromASettledOne(): void
    {
        $captured = [];
        $client = $this->client([
            'items' => [
                [
                    'task_id' => 't1',
                    'status' => 'broadcasted',
                    'wallet_address' => '0xa',
                    'chain' => 'ETH_MAINNET',
                    'sweep_confirmations' => 2,
                    'type_work' => 'threshold',
                    'total_fee_usd' => '1.20',
                ],
                [
                    'task_id' => 't2',
                    'status' => 'completed',
                    'wallet_address' => '0xb',
                    'chain' => 'ETH_MAINNET',
                    'sweep_confirmations' => 12,
                    'completed_at' => '2026-08-28T10:00:00Z',
                    'real_sweep_fee_usd' => '0.98',
                ],
            ],
            'meta' => ['total' => 2, 'page' => 1, 'page_size' => 50],
        ], $captured);

        $out = $client->sweeps()->history();

        self::assertNotNull($out->items);
        [$inFlight, $settled] = $out->items;

        self::assertSame(SweepStatus::Broadcasted->value, $inFlight->status);
        self::assertSame(2, $inFlight->sweepConfirmations);
        // Still in flight: there is no settlement moment to report yet.
        self::assertNull($inFlight->completedAt);
        self::assertSame('threshold', $inFlight->typeWork);
        self::assertSame('1.20', $inFlight->totalFeeUsd);

        self::assertSame(SweepStatus::Completed->value, $settled->status);
        self::assertSame('2026-08-28T10:00:00Z', $settled->completedAt);
        self::assertSame('0.98', $settled->realSweepFeeUsd);
        self::assertTrue(SweepStatus::from($settled->status)->isSettled());
    }

    public function testEnvironmentReachesTheWireAndIsOmittedWhenUnset(): void
    {
        $captured = [];
        $client = $this->client(['uuid' => 'u1', 'status' => 'pending'], $captured);

        $client->payIns()->create(new CreatePayInRequest(
            orderId: 'o1',
            userId: 'u',
            mode: 'fiat',
            environment: Environment::Testnet->value,
            amountFiat: '10',
            currency: 'USD',
        ));
        self::assertSame('testnet', $this->sentBody($captured)['environment']);

        $captured2 = [];
        $client2 = $this->client(['uuid' => 'u2', 'status' => 'pending'], $captured2);
        $client2->payIns()->create(new CreatePayInRequest(
            orderId: 'o2',
            userId: 'u',
            mode: 'fiat',
            amountFiat: '10',
            currency: 'USD',
        ));
        // Unset must stay off the wire: an empty string is a value the platform has to
        // reject, not the "use the project default" the caller meant.
        self::assertArrayNotHasKey('environment', $this->sentBody($captured2));
    }
}
