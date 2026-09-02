<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Clear;
use CryptoChief\Processing\Client;
use CryptoChief\Processing\Dto\CreatePayInRequest;
use CryptoChief\Processing\Dto\SweepHistoryQuery;
use CryptoChief\Processing\Environment;
use CryptoChief\Processing\SweepFeeMode;
use CryptoChief\Processing\SweepGasSource;
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
                'gas_source' => 'native',
                'source' => 'wallet',
            ],
            'override' => [
                'network_code' => '',
                'type_work' => 'threshold',
                'threshold_amount_usd' => '250',
                'fee_mode' => null,
                'gas_source' => null,
                'source' => 'merchant',
                'locked' => false,
            ],
            'project_default' => ['type_work' => 'momentum', 'fee_mode' => 'client', 'gas_source' => 'native'],
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

    public function testGasSourceIsNullOnTheOverrideAndConcreteOnTheEffectivePolicy(): void
    {
        $captured = [];
        // A TRON wallet that has never chosen a gas source. It overrides the mode and
        // nothing else, so the platform default decides what buys the energy.
        $client = $this->client([
            'wallet_address' => 'TQrY8bYc2yQ8sM8nJ1sZ9c2Zx7L2wq7pQb',
            'network_code' => 'TRON_MAINNET',
            'effective' => [
                'type_work' => 'momentum',
                'fee_mode' => 'mix',
                'gas_source' => 'rented',
                'source' => 'default',
            ],
            'override' => [
                'network_code' => '',
                'type_work' => 'momentum',
                'threshold_amount_usd' => null,
                'fee_mode' => null,
                'gas_source' => null,
                'source' => 'merchant',
                'locked' => false,
            ],
            'project_default' => ['type_work' => 'momentum', 'fee_mode' => 'mix', 'gas_source' => 'rented'],
        ], $captured);

        $out = $client->sweeps()->settings('TQrY8bYc2yQ8sM8nJ1sZ9c2Zx7L2wq7pQb', 'TRON_MAINNET');

        self::assertNotNull($out->override);
        // null on the override layer says "this layer does not decide it" - inherited, NOT
        // switched off. Inheritance is per field, which the mode next to it proves: the
        // wallet does decide that one.
        self::assertNull($out->override->gasSource);
        self::assertSame('momentum', $out->override->typeWork);

        // The effective layer always carries a concrete value, and this is the one to read.
        // Nobody switched anything on, and the answer is still `rented`: the platform
        // supplies the energy and bills it to API credits. Not choosing is not `native`.
        self::assertNotNull($out->effective);
        self::assertSame(SweepGasSource::Rented->value, $out->effective->gasSource);
        self::assertSame(SweepGasSource::Rented, SweepGasSource::from($out->effective->gasSource));
        self::assertNotSame(SweepGasSource::Native->value, $out->effective->gasSource);

        self::assertNotNull($out->projectDefault);
        self::assertSame('rented', $out->projectDefault->gasSource);
    }

    public function testUpdateSendsGasSourceAndNamesItInTheFieldsMask(): void
    {
        $captured = [];
        $client = $this->client($this->settingsPayload(), $captured);

        $client->sweeps()->updateSettings(
            address: 'TQrY8bYc2yQ8sM8nJ1sZ9c2Zx7L2wq7pQb',
            gasSource: SweepGasSource::Native,
        );

        $body = $this->sentBody($captured);
        self::assertSame('native', $body['gas_source']);
        self::assertSame(['gas_source'], $body['fields']);
        // Writing the gas source alone leaves the mode and fee mode as they were.
        self::assertArrayNotHasKey('type_work', $body);
        self::assertArrayNotHasKey('fee_mode', $body);
    }

    public function testUpdateOmitsGasSourceEntirelyWhenItIsNotGiven(): void
    {
        $captured = [];
        $client = $this->client($this->settingsPayload(), $captured);

        $client->sweeps()->updateSettings(address: '0xabc', feeMode: 'client');

        // Not sending it is not the same as sending "native": the stored value is left
        // untouched, and where nothing is stored the platform default `rented` applies.
        $body = $this->sentBody($captured);
        self::assertArrayNotHasKey('gas_source', $body);
        self::assertSame(['fee_mode'], $body['fields']);
    }

    public function testClearingGasSourceNamesItInTheMaskWithNoValue(): void
    {
        $captured = [];
        $client = $this->client($this->settingsPayload(), $captured);

        $client->sweeps()->updateSettings(
            address: 'TQrY8bYc2yQ8sM8nJ1sZ9c2Zx7L2wq7pQb',
            feeMode: SweepFeeMode::Mix,
            gasSource: Clear::value(),
        );

        // Named in `fields`, absent from the body: that pair is what drops the override
        // and goes back to inheriting, and it is the only way to clear one field while
        // keeping the others - fee_mode here is written in the same call.
        $body = $this->sentBody($captured);
        self::assertSame(['fee_mode', 'gas_source'], $body['fields']);
        self::assertArrayNotHasKey('gas_source', $body);
        self::assertSame('mix', $body['fee_mode']);
    }

    public function testHistoryFiltersByStatusAndSearch(): void
    {
        $captured = [];
        $client = $this->client(['items' => [], 'meta' => ['total' => 0, 'page' => 1, 'page_size' => 20]], $captured);

        $client->sweeps()->history(new SweepHistoryQuery(
            mode: 'auto',
            status: SweepStatus::Failed->value,
            search: '0x6269770518fed44768eb719555f0be45858f5ae2',
            page: 2,
            pageSize: 50,
        ));

        $body = $this->sentBody($captured);
        self::assertSame('auto', $body['mode']);
        self::assertSame('failed', $body['status']);
        // A substring of the wallet address, either transaction hash, or the task_id.
        self::assertSame('0x6269770518fed44768eb719555f0be45858f5ae2', $body['search']);
        self::assertSame(2, $body['page']);
        self::assertSame(50, $body['page_size']);
    }

    public function testWalletHistoryForwardsStatusAndSearchAlongsideTheAddress(): void
    {
        $captured = [];
        $client = $this->client(['items' => [], 'meta' => ['total' => 0, 'page' => 1, 'page_size' => 20]], $captured);

        $client->sweeps()->walletHistory('0x77EDde3213b70c9dd224C874c28f41B23B070f65', new SweepHistoryQuery(
            status: SweepStatus::Skipped->value,
            search: '898cdbd0-d583-4089-9c53-15f5ca9b53dc',
        ));

        /** @var array{request: RequestInterface} $entry */
        $entry = $captured[0];
        self::assertSame('/v1/sweeps/wallet/history', $entry['request']->getUri()->getPath());

        // The address is required here and the filters ride alongside it. Dropping either
        // filter would silently widen the page instead of narrowing it.
        $body = $this->sentBody($captured);
        self::assertSame('0x77EDde3213b70c9dd224C874c28f41B23B070f65', $body['address']);
        self::assertSame('skipped', $body['status']);
        self::assertSame('898cdbd0-d583-4089-9c53-15f5ca9b53dc', $body['search']);
        self::assertArrayNotHasKey('mode', $body);
        self::assertArrayNotHasKey('page', $body);
    }

    public function testUnfilteredHistoryIncludesSkippedSweeps(): void
    {
        $captured = [];
        $client = $this->client([
            'items' => [
                ['task_id' => 't1', 'status' => 'skipped', 'wallet_address' => '0xa', 'chain' => 'ETH_MAINNET'],
                ['task_id' => 't2', 'status' => 'completed', 'wallet_address' => '0xb', 'chain' => 'ETH_MAINNET'],
            ],
            'meta' => ['total' => 2, 'page' => 1, 'page_size' => 20],
        ], $captured);

        $out = $client->sweeps()->history();

        // No status filter means every status, `skipped` among them - a balance below the
        // wallet's threshold, which is a normal outcome and not a failure. An unfiltered
        // page is therefore not a page of sweeps that all moved money.
        /** @var array{request: RequestInterface} $entry */
        $entry = $captured[0];
        self::assertSame('{}', (string) $entry['request']->getBody());

        self::assertNotNull($out->items);
        self::assertSame(SweepStatus::Skipped->value, $out->items[0]->status);
        self::assertFalse(SweepStatus::from($out->items[0]->status)->isSettled());
        self::assertFalse(SweepStatus::from($out->items[0]->status)->isInFlight());
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
