<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Client;
use CryptoChief\Processing\Dto\EstimatePayoutResponse;
use CryptoChief\Processing\Dto\ExecutePayoutRequest;
use CryptoChief\Processing\Dto\ExecuteTransactionRequest;
use CryptoChief\Processing\Dto\PayoutInfo;
use CryptoChief\Processing\Dto\PayoutSource;
use CryptoChief\Processing\Dto\Sweep;
use CryptoChief\Processing\Dto\TransactionInfo;
use CryptoChief\Processing\Dto\Withdrawal;
use CryptoChief\Processing\SweepStatus;
use CryptoChief\Processing\Webhook\PayoutEvent;
use CryptoChief\Processing\Webhook\SweepEvent;
use CryptoChief\Processing\Webhook\TransactionEvent;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionClass;

/**
 * Confirmation counts on payouts, sign/execute transactions, sweeps and withdrawals.
 *
 * They differ on purpose. A payout's counts are optional - a source that has not been
 * seen on chain has none, and a payout with no transaction has no top-level count - and
 * so is its finality depth, which a payout recorded before the platform kept one does not
 * carry. A transaction always carries both its count and its network's threshold, so 0
 * there is a value, not a missing one: 0 while it is not in a block, then growing while it
 * is broadcasted, until it is confirmed at the threshold. A sweep's depth is always in the history and
 * optional on the webhook. A withdrawal always carries its depth and has a count only
 * once its transaction has been seen in a block.
 */
final class ConfirmationsTest extends TestCase
{
    /**
     * @param array<int, mixed> $captured
     * @param array<string, mixed> ...$payloads
     */
    private static function client(array &$captured, array ...$payloads): Client
    {
        $responses = [];
        foreach ($payloads as $payload) {
            $responses[] = new Response(200, ['Content-Type' => 'application/json'], json_encode($payload) ?: '');
        }
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($captured));

        return new Client(merchantId: 'M', apiKey: 'K', httpClient: new GuzzleClient(['handler' => $stack]));
    }

    /**
     * @param array<int, mixed> $captured
     */
    private static function sentPath(array $captured, int $i = 0): string
    {
        /** @var array{request: RequestInterface} $entry */
        $entry = $captured[$i];

        return $entry['request']->getUri()->getPath();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function source(array $overrides = []): array
    {
        return $overrides + [
            'address' => '0xsource',
            'network' => 'ETH_SEPOLIA',
            'coin' => 'ETH',
            'amount_crypto' => '0.0001',
            'need_refuel' => false,
            'refuel_amount' => '0',
            'estimated_fee' => '0.00002',
            'estimated_fee_fiat' => '0.05',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function payout(array $overrides = []): array
    {
        return $overrides + [
            'uuid' => 'pay-1',
            'order_id' => 'order-1',
            'user_id' => 'user-1',
            'status' => 'paid',
            'amount_requested' => '0.0002',
            'amount_to_receive' => '0.0002',
            'to_address' => '0xrecipient',
            'fee_info' => ['fee_mode' => 'service', 'limit_currency' => 'USD'],
            'sources' => [],
            'created_at' => '2026-09-14T10:00:00Z',
            'completed_at' => null,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function transaction(array $overrides = []): array
    {
        return $overrides + [
            'uuid' => 'tx-1',
            'status' => 'confirmed',
            'network' => 'ETH_SEPOLIA',
            'chain_family' => 'EVM',
            'type' => 'native',
            'from_address' => '0xfrom',
            'to_address' => '0xto',
            'value' => '1000',
            'tx_hash' => '0xhash',
            'confirmations' => 12,
            'required_confirmations' => 12,
            'expires_at' => '2026-09-14T10:10:00Z',
            'created_at' => '2026-09-14T10:00:00Z',
            'completed_at' => '2026-09-14T10:03:00Z',
        ];
    }

    /**
     * A withdrawal exactly as `/v1/withdrawal/info` sends it; the gateway passes the
     * processing response through unchanged.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function withdrawal(array $overrides = []): array
    {
        return $overrides + [
            'uuid' => 'wd-1',
            'status' => 'completed',
            'from_address' => '0xmaster',
            'to_address' => '0xrecipient',
            'amount' => '100.500000',
            'network' => 'ETH_MAINNET',
            'coin' => 'USDT',
            'need_refuel' => false,
            'tx_hash' => '0xwithdrawal',
            'required_confirmations' => 12,
            'estimated_fee_fiat' => '1.20',
            'fee_mode' => 'service',
            'created_at' => '2026-09-14T10:00:00Z',
        ];
    }

    // -- Payout ---------------------------------------------------------------

    public function testPayoutInfoReadsEachSourceServiceOperationAndTheLowest(): void
    {
        $captured = [];
        $client = self::client($captured, self::payout([
            // Still settling: 4 of 32 is a payout in progress, not a paid one.
            'status' => 'processing',
            'sources' => [
                self::source(['address' => '0xa', 'txid' => '0x1', 'confirmations' => 15]),
                self::source(['address' => '0xb', 'txid' => '0x2', 'confirmations' => 4]),
            ],
            'service_operations' => [[
                'type' => 'gas_refuel',
                'context' => 'payout_prepare',
                'status' => 'done',
                'network' => 'ETH_SEPOLIA',
                'coin' => 'ETH',
                'amount_native' => '0.001',
                'from_address' => '0xmaster',
                'to_address' => '0xb',
                'estimated_fee' => '0.00002',
                'estimated_fee_fiat' => '0.05',
                'txid' => '0x3',
                'confirmations' => 20,
            ]],
            'confirmations' => 4,
            'required_confirmations' => 32,
        ]));

        $p = $client->payouts()->info('pay-1');

        self::assertSame('/v1/payout/info', self::sentPath($captured));
        self::assertSame(4, $p->confirmations);
        self::assertSame(32, $p->requiredConfirmations);
        self::assertNotNull($p->sources);
        self::assertCount(2, $p->sources);
        self::assertInstanceOf(PayoutSource::class, $p->sources[0]);
        self::assertSame(15, $p->sources[0]->confirmations);
        self::assertSame(4, $p->sources[1]->confirmations);
        // The amount travels as `amount_crypto`; `amount` is not on the wire.
        self::assertSame('0.0001', $p->sources[0]->amountCrypto);
        self::assertNull($p->sources[0]->amount);
        self::assertSame('0x1', $p->sources[0]->txid);
        self::assertSame('0x2', $p->sources[1]->txid);
        self::assertSame('ETH_SEPOLIA', $p->sources[0]->network);
        self::assertFalse($p->sources[0]->needRefuel);
        self::assertSame('0', $p->sources[0]->refuelAmount);
        self::assertSame('0.00002', $p->sources[0]->estimatedFee);
        self::assertSame('0.05', $p->sources[0]->estimatedFeeFiat);
        self::assertNotNull($p->serviceOperations);
        self::assertSame(20, $p->serviceOperations[0]['confirmations']);
        self::assertSame('gas_refuel', $p->serviceOperations[0]['type']);
    }

    /**
     * Before anything is sent the platform omits every count. Absent must read as `null`
     * - never as 0, which would claim a transaction exists and is unconfirmed.
     */
    public function testPayoutWithNoTransactionHasNoCounts(): void
    {
        $captured = [];
        $client = self::client($captured, self::payout([
            'status' => 'queue',
            'sources' => [self::source()],
            'required_confirmations' => 32,
        ]));

        $p = $client->payouts()->info('pay-1');

        // The depth is known from creation; the counts are not.
        self::assertSame(32, $p->requiredConfirmations);
        self::assertNull($p->confirmations);
        self::assertNotNull($p->sources);
        self::assertNull($p->sources[0]->confirmations);
        self::assertNull($p->serviceOperations);
        self::assertArrayNotHasKey('confirmations', $p->toWire());
    }

    /**
     * A source that has broadcast but was not counted yet has no count of its own, and
     * pulls the payout's lowest down to 0.
     */
    public function testBroadcastSourceWithoutACountIsNullButTheLowestIsZero(): void
    {
        $captured = [];
        $client = self::client($captured, self::payout([
            'status' => 'processing',
            'sources' => [
                self::source(['txid' => '0x1', 'confirmations' => 9]),
                self::source(['txid' => '0x2']),
            ],
            'service_operations' => [['type' => 'gas_refuel', 'status' => 'sent', 'txid' => '0x3']],
            'confirmations' => 0,
            'required_confirmations' => 32,
        ]));

        $p = $client->payouts()->info('pay-1');

        self::assertSame(0, $p->confirmations);
        self::assertSame(32, $p->requiredConfirmations);
        self::assertFalse($p->isTerminal());
        self::assertNotNull($p->sources);
        self::assertSame(9, $p->sources[0]->confirmations);
        self::assertNull($p->sources[1]->confirmations);
        self::assertNotNull($p->serviceOperations);
        self::assertArrayNotHasKey('confirmations', $p->serviceOperations[0]);
    }

    public function testPayoutHistoryItemsCarryTheirOwnCounts(): void
    {
        $captured = [];
        $client = self::client($captured, [
            'items' => [
                self::payout([
                    'uuid' => 'pay-1',
                    'sources' => [self::source(['txid' => '0x1', 'confirmations' => 30])],
                    'confirmations' => 30,
                    'required_confirmations' => 20,
                ]),
                self::payout(['uuid' => 'pay-2', 'status' => 'queue', 'sources' => [self::source()]]),
            ],
            'meta' => ['page' => 1, 'page_size' => 20, 'total' => 2, 'total_pages' => 1],
        ]);

        $h = $client->payouts()->history();

        self::assertSame('/v1/payout/history', self::sentPath($captured));
        self::assertNotNull($h->items);
        self::assertCount(2, $h->items);
        self::assertInstanceOf(PayoutInfo::class, $h->items[0]);
        self::assertSame(30, $h->items[0]->confirmations);
        self::assertNotNull($h->items[0]->sources);
        self::assertSame(30, $h->items[0]->sources[0]->confirmations);
        self::assertSame(20, $h->items[0]->requiredConfirmations);
        self::assertNull($h->items[1]->confirmations);
        // A response without the depth still decodes; it reads as null, never as 0.
        self::assertNull($h->items[1]->requiredConfirmations);
    }

    /** A repeated execute with the same order_id answers with the stored payout, counts included. */
    public function testPayoutExecuteReplayCarriesCounts(): void
    {
        $captured = [];
        $client = self::client($captured, self::payout([
            'status' => 'processing',
            'sources' => [self::source(['txid' => '0x1', 'confirmations' => 7])],
            'confirmations' => 7,
            'required_confirmations' => 12,
        ]));

        $p = $client->payouts()->execute(new ExecutePayoutRequest(
            network: 'ETH_SEPOLIA',
            coin: 'ETH',
            amount: '0.0002',
            toAddress: '0xrecipient',
            orderId: 'order-1',
        ));

        self::assertSame('/v1/payout/execute', self::sentPath($captured));
        self::assertSame(7, $p->confirmations);
        self::assertSame(12, $p->requiredConfirmations);
        self::assertNotNull($p->sources);
        self::assertSame(7, $p->sources[0]->confirmations);
    }

    public function testEstimateSourcesHaveNoCount(): void
    {
        $e = EstimatePayoutResponse::fromWire([
            'amount_to_receive' => '0.0002',
            'sources' => [self::source()],
        ]);

        self::assertNotNull($e->sources);
        self::assertInstanceOf(PayoutSource::class, $e->sources[0]);
        self::assertNull($e->sources[0]->confirmations);
        self::assertNull($e->sources[0]->txid);
        self::assertSame('0.0001', $e->sources[0]->amountCrypto);
    }

    public function testPaidSourceReadsFeePaid(): void
    {
        $s = PayoutSource::fromWire(self::source([
            'txid' => '0xpaid',
            'fee_paid' => '0.000021',
            'fee_paid_fiat' => '0.06',
            'confirmations' => 32,
        ]));

        self::assertSame('0.000021', $s->feePaid);
        self::assertSame('0.06', $s->feePaidFiat);
        self::assertSame('0xpaid', $s->txid);
        self::assertSame('0.0001', $s->toWire()['amount_crypto']);
        self::assertArrayNotHasKey('amount', $s->toWire());
    }

    /** A payout on a slow UTXO network settles in about an hour; the default has to cover it. */
    public function testPayoutWaitForDefaultTimeoutIsNinetyMinutes(): void
    {
        $param = (new \ReflectionMethod(\CryptoChief\Processing\Service\PayoutsService::class, 'waitFor'))
            ->getParameters()[2];

        self::assertSame('timeoutSec', $param->getName());
        self::assertSame(5400.0, $param->getDefaultValue());
    }

    // -- Transaction ----------------------------------------------------------

    public function testTransactionInfoReadsCountAndThreshold(): void
    {
        $captured = [];
        $client = self::client($captured, self::transaction());

        $t = $client->transactions()->info('tx-1');

        self::assertSame('/v1/transaction/info', self::sentPath($captured));
        self::assertSame(12, $t->confirmations);
        self::assertSame(12, $t->requiredConfirmations);
    }

    /**
     * Right after execute the transaction is not in a block yet: the count is a real 0
     * against the network's threshold.
     */
    public function testTransactionExecuteReportsZeroAgainstTheThreshold(): void
    {
        $captured = [];
        $client = self::client($captured, self::transaction([
            'status' => 'broadcasted',
            'confirmations' => 0,
            'required_confirmations' => 12,
            'completed_at' => null,
        ]));

        $t = $client->transactions()->execute(new ExecuteTransactionRequest(uuid: 'tx-1'));

        self::assertSame('/v1/transaction/execute', self::sentPath($captured));
        self::assertSame(0, $t->confirmations);
        self::assertSame(12, $t->requiredConfirmations);
        self::assertSame(['confirmations' => 0, 'required_confirmations' => 12], array_intersect_key(
            $t->toWire(),
            ['confirmations' => true, 'required_confirmations' => true]
        ));
    }

    /**
     * In a block but short of the threshold: still `broadcasted`, with a count that grows
     * on every platform check and can drop after a reorganisation. A count above 0 is not
     * confirmation; `confirmed` is.
     */
    public function testBroadcastedTransactionInABlockReadsAGrowingCount(): void
    {
        $captured = [];
        $inBlock = ['status' => 'broadcasted', 'required_confirmations' => 12, 'completed_at' => null];
        $client = self::client(
            $captured,
            self::transaction(['confirmations' => 3] + $inBlock),
            self::transaction(['confirmations' => 7] + $inBlock),
            // Reorganisation: the chain now reports fewer blocks on top of the transaction.
            self::transaction(['confirmations' => 5] + $inBlock),
        );

        $first = $client->transactions()->info('tx-1');
        $deeper = $client->transactions()->info('tx-1');
        $reorged = $client->transactions()->info('tx-1');

        self::assertSame('/v1/transaction/info', self::sentPath($captured));
        self::assertSame('broadcasted', $first->status);
        self::assertSame(3, $first->confirmations);
        self::assertSame(12, $first->requiredConfirmations);
        self::assertFalse($first->isTerminal());
        self::assertSame(3, $first->toWire()['confirmations']);

        self::assertSame(7, $deeper->confirmations);
        self::assertFalse($deeper->isTerminal());

        self::assertSame(5, $reorged->confirmations);
        self::assertSame(12, $reorged->requiredConfirmations);
        self::assertFalse($reorged->isTerminal());
    }

    /** waitFor() keeps polling through a growing count and returns only on `confirmed`. */
    public function testWaitForDoesNotStopOnABroadcastedCount(): void
    {
        $captured = [];
        $inBlock = ['status' => 'broadcasted', 'required_confirmations' => 12, 'completed_at' => null];
        $client = self::client(
            $captured,
            self::transaction(['confirmations' => 0] + $inBlock),
            self::transaction(['confirmations' => 1] + $inBlock),
            self::transaction(['confirmations' => 11] + $inBlock),
            self::transaction(['confirmations' => 12, 'required_confirmations' => 12]),
        );

        $t = $client->transactions()->waitFor('tx-1', intervalSec: 0.001, timeoutSec: 5.0);

        self::assertCount(4, $captured);
        self::assertSame('confirmed', $t->status);
        self::assertSame(12, $t->confirmations);
        self::assertSame(12, $t->requiredConfirmations);
    }

    public function testTransactionHistoryItemsCarryCountAndThreshold(): void
    {
        $captured = [];
        $client = self::client($captured, [
            'items' => [
                self::transaction(['uuid' => 'tx-1', 'confirmations' => 20, 'required_confirmations' => 20]),
                self::transaction([
                    'uuid' => 'tx-2',
                    'status' => 'failed',
                    'error_reason' => 'reverted',
                    'confirmations' => 0,
                    'required_confirmations' => 1,
                ]),
                self::transaction([
                    'uuid' => 'tx-3',
                    'status' => 'broadcasted',
                    'confirmations' => 9,
                    'required_confirmations' => 20,
                    'completed_at' => null,
                ]),
            ],
            'meta' => ['page' => 1, 'page_size' => 20, 'total' => 3, 'total_pages' => 1],
        ]);

        $h = $client->transactions()->history();

        self::assertSame('/v1/transaction/history', self::sentPath($captured));
        self::assertNotNull($h->items);
        self::assertCount(3, $h->items);
        self::assertInstanceOf(TransactionInfo::class, $h->items[1]);
        self::assertSame(20, $h->items[0]->confirmations);
        self::assertSame(20, $h->items[0]->requiredConfirmations);
        self::assertSame(0, $h->items[1]->confirmations);
        self::assertSame(1, $h->items[1]->requiredConfirmations);
        self::assertSame('broadcasted', $h->items[2]->status);
        self::assertSame(9, $h->items[2]->confirmations);
        self::assertSame(20, $h->items[2]->requiredConfirmations);
        self::assertFalse($h->items[2]->isTerminal());
    }

    // -- Sweep ----------------------------------------------------------------

    /**
     * A sweep in a block but short of the network's depth stays broadcasted while its count
     * grows. A count above zero is therefore not settlement; `completed` is.
     */
    public function testSweepHistoryCountAboveZeroIsNotSettlement(): void
    {
        $captured = [];
        $client = self::client($captured, [
            'items' => [
                [
                    'task_id' => 't-settling',
                    'status' => 'broadcasted',
                    'chain' => 'ETH_MAINNET',
                    'sweep_confirmations' => 3,
                    'required_confirmations' => 32,
                ],
                [
                    'task_id' => 't-final',
                    'status' => 'completed',
                    'chain' => 'ETH_MAINNET',
                    'sweep_confirmations' => 32,
                    'required_confirmations' => 32,
                ],
                [
                    // Marked completed at broadcast by an older version and never observed
                    // on chain: the status says settled, the platform never saw it.
                    'task_id' => 't-legacy-unseen',
                    'status' => 'completed',
                    'chain' => 'ETH_MAINNET',
                    'sweep_tx_hash' => '0xunseen',
                    'sweep_confirmations' => 0,
                    'required_confirmations' => 32,
                ],
            ],
            'meta' => ['total' => 3, 'page' => 1, 'page_size' => 20],
        ]);

        $h = $client->sweeps()->history();

        self::assertSame('/v1/sweeps/history', self::sentPath($captured));
        self::assertNotNull($h->items);
        self::assertCount(3, $h->items);
        [$settling, $final, $unseen] = $h->items;
        self::assertInstanceOf(Sweep::class, $settling);

        self::assertSame(3, $settling->sweepConfirmations);
        self::assertSame(32, $settling->requiredConfirmations);
        self::assertFalse(SweepStatus::from($settling->status)->isSettled());
        self::assertTrue(SweepStatus::from($settling->status)->isInFlight());
        self::assertFalse($settling->isFinal());

        self::assertSame(32, $final->sweepConfirmations);
        self::assertSame(32, $final->requiredConfirmations);
        self::assertTrue(SweepStatus::from($final->status)->isSettled());
        self::assertTrue($final->isFinal());

        // Decoded as sent: a real 0, not null, so a consumer can single this row out.
        self::assertSame('completed', $unseen->status);
        self::assertSame(0, $unseen->sweepConfirmations);
        self::assertSame(32, $unseen->requiredConfirmations);
        self::assertSame('0xunseen', $unseen->sweepTxHash);
        self::assertTrue(SweepStatus::from($unseen->status)->isSettled());
        self::assertFalse($unseen->isFinal());
    }

    public function testSweepIsFinalNeedsCompletedAndACount(): void
    {
        // Above the depth but not completed.
        self::assertFalse(Sweep::fromWire([
            'status' => 'failed', 'sweep_confirmations' => 40, 'required_confirmations' => 32,
        ])->isFinal());
        // Completed without a count: nothing to compare.
        self::assertFalse(Sweep::fromWire(['status' => 'completed'])->isFinal());
        self::assertFalse(Sweep::fromWire(['status' => 'completed', 'required_confirmations' => 32])->isFinal());
        // Without a depth: any count above zero.
        self::assertTrue(Sweep::fromWire(['status' => 'completed', 'sweep_confirmations' => 32])->isFinal());
        self::assertTrue(Sweep::fromWire(['status' => 'completed', 'sweep_confirmations' => 1])->isFinal());
        self::assertFalse(Sweep::fromWire(['status' => 'completed', 'sweep_confirmations' => 0])->isFinal());
        self::assertFalse(Sweep::fromWire(['status' => 'broadcasted', 'sweep_confirmations' => 5])->isFinal());
        // A depth of 0 still needs one confirmation.
        self::assertFalse(Sweep::fromWire([
            'status' => 'completed', 'sweep_confirmations' => 0, 'required_confirmations' => 0,
        ])->isFinal());
        self::assertTrue(Sweep::fromWire([
            'status' => 'completed', 'sweep_confirmations' => 33, 'required_confirmations' => 32,
        ])->isFinal());
    }

    public function testSweepWalletHistoryCarriesTheDepth(): void
    {
        $captured = [];
        $client = self::client($captured, [
            'items' => [[
                // Finality without a block count is published as the depth itself.
                'task_id' => 't-sol',
                'status' => 'completed',
                'chain' => 'SOLANA_MAINNET',
                'sweep_confirmations' => 32,
                'required_confirmations' => 32,
            ]],
            'meta' => ['total' => 1, 'page' => 1, 'page_size' => 20],
        ]);

        $h = $client->sweeps()->walletHistory('So1anaAddress');

        self::assertSame('/v1/sweeps/wallet/history', self::sentPath($captured));
        self::assertNotNull($h->items);
        self::assertSame(32, $h->items[0]->sweepConfirmations);
        self::assertSame(32, $h->items[0]->requiredConfirmations);
        self::assertSame(32, $h->items[0]->toWire()['required_confirmations']);
    }

    public function testSweepWithoutTheDepthDecodesAsNull(): void
    {
        $s = Sweep::fromWire(['task_id' => 't1', 'status' => 'broadcasted', 'sweep_confirmations' => 0]);

        self::assertSame(0, $s->sweepConfirmations);
        self::assertNull($s->requiredConfirmations);
        self::assertArrayNotHasKey('required_confirmations', $s->toWire());
    }

    // -- Withdrawal -----------------------------------------------------------

    /**
     * In a block but short of the network's depth: `confirm_check` with a growing count.
     * A count above zero is not completion; `completed` is.
     */
    public function testWithdrawalInfoInConfirmCheckReadsCountAndDepth(): void
    {
        $captured = [];
        $client = self::client($captured, self::withdrawal([
            'status' => 'confirm_check',
            'need_refuel' => true,
            'refuel_tx_hash' => '0xrefuel',
            'refuel_status' => 'done',
            'confirmations' => 3,
            'required_confirmations' => 12,
        ]));

        $w = $client->withdrawals()->info('wd-1');

        self::assertSame('/v1/withdrawal/info', self::sentPath($captured));
        /** @var array{request: RequestInterface} $entry */
        $entry = $captured[0];
        self::assertSame('{"uuid":"wd-1"}', (string) $entry['request']->getBody());

        self::assertInstanceOf(Withdrawal::class, $w);
        self::assertSame('confirm_check', $w->status);
        self::assertSame(3, $w->confirmations);
        self::assertSame(12, $w->requiredConfirmations);
        // Not final yet: no settlement moment and no actual fee.
        self::assertNull($w->completedAt);
        self::assertNull($w->actualFeeFiat);
        self::assertNull($w->errorReason);

        // The rest of the real response decodes too.
        self::assertSame('0xwithdrawal', $w->txHash);
        self::assertTrue($w->needRefuel);
        self::assertSame('0xrefuel', $w->refuelTxHash);
        self::assertSame('done', $w->refuelStatus);
        self::assertSame('1.20', $w->estimatedFeeFiat);
        self::assertSame('service', $w->feeMode);
        self::assertSame('100.500000', $w->amount);
        self::assertSame('0xmaster', $w->fromAddress);
    }

    /**
     * Sent but not seen in a block yet, or not sent at all: the platform omits the count.
     * Absent must read as `null` - never as 0 - while the depth is always there.
     */
    public function testWithdrawalWithoutABlockHasNoCountButHasTheDepth(): void
    {
        $captured = [];
        $client = self::client(
            $captured,
            self::withdrawal(['status' => 'confirm_check']),
            self::withdrawal([
                'status' => 'in_mempool',
                'network' => 'BTC_MAINNET',
                'coin' => 'BTC',
                'required_confirmations' => 2,
            ]),
            array_diff_key(self::withdrawal(['status' => 'queue']), ['tx_hash' => true]),
        );

        $sent = $client->withdrawals()->info('wd-1');
        $mempool = $client->withdrawals()->info('wd-1');
        $queued = $client->withdrawals()->info('wd-1');

        self::assertSame('confirm_check', $sent->status);
        self::assertNull($sent->confirmations);
        self::assertSame(12, $sent->requiredConfirmations);
        self::assertArrayNotHasKey('confirmations', $sent->toWire());

        self::assertSame('in_mempool', $mempool->status);
        self::assertNull($mempool->confirmations);
        self::assertSame(2, $mempool->requiredConfirmations);

        self::assertSame('queue', $queued->status);
        self::assertNull($queued->txHash);
        self::assertNull($queued->confirmations);
        self::assertSame(12, $queued->requiredConfirmations);
    }

    public function testWithdrawalHistoryItemsCarryTheirOwnCounts(): void
    {
        $captured = [];
        $client = self::client($captured, [
            'items' => [
                self::withdrawal([
                    'uuid' => 'wd-done',
                    'confirmations' => 12,
                    'required_confirmations' => 12,
                    'actual_fee_fiat' => '1.18',
                    'completed_at' => '2026-09-14T10:05:00Z',
                ]),
                self::withdrawal([
                    // Finality without a block count is published as the depth itself.
                    'uuid' => 'wd-sol',
                    'network' => 'SOLANA_MAINNET',
                    'coin' => 'SOL',
                    'confirmations' => 32,
                    'required_confirmations' => 32,
                    'completed_at' => '2026-09-14T10:01:00Z',
                ]),
                self::withdrawal([
                    // Never reached a block before the confirmation timeout.
                    'uuid' => 'wd-failed',
                    'status' => 'failed',
                    'error_reason' => 'TX_CONFIRM_TIMEOUT',
                ]),
                self::withdrawal([
                    // Completed before the count was stored: the platform publishes the depth
                    // as the count.
                    'uuid' => 'wd-legacy',
                    'confirmations' => 12,
                    'required_confirmations' => 12,
                    'completed_at' => '2026-02-10T12:02:30Z',
                ]),
            ],
            'meta' => ['page' => 1, 'page_size' => 20, 'total' => 4, 'total_pages' => 1],
        ]);

        $h = $client->withdrawals()->history();

        self::assertSame('/v1/withdrawal/history', self::sentPath($captured));
        self::assertNotNull($h->items);
        self::assertCount(4, $h->items);
        [$done, $sol, $failed, $legacy] = $h->items;
        self::assertInstanceOf(Withdrawal::class, $done);
        self::assertNotNull($h->meta);
        self::assertSame(4, $h->meta->total);

        self::assertSame('completed', $done->status);
        self::assertSame(12, $done->confirmations);
        self::assertSame(12, $done->requiredConfirmations);
        self::assertSame('1.18', $done->actualFeeFiat);
        self::assertSame('2026-09-14T10:05:00Z', $done->completedAt);

        self::assertSame(32, $sol->confirmations);
        self::assertSame(32, $sol->requiredConfirmations);

        self::assertSame('failed', $failed->status);
        self::assertSame('TX_CONFIRM_TIMEOUT', $failed->errorReason);
        self::assertNull($failed->confirmations);
        self::assertNull($failed->completedAt);
        // The reason is `error_reason` on the wire; the old `error` field never fills.
        self::assertNull($failed->error);

        self::assertSame('completed', $legacy->status);
        self::assertSame(12, $legacy->confirmations);
        self::assertSame(12, $legacy->requiredConfirmations);
    }

    /** A count the platform sends as 0 is a value and decodes as 0, not as missing. */
    public function testWithdrawalZeroCountIsAValue(): void
    {
        $w = Withdrawal::fromWire(self::withdrawal([
            'status' => 'confirm_check',
            'confirmations' => 0,
            'required_confirmations' => 6,
        ]));

        self::assertSame(0, $w->confirmations);
        self::assertSame(6, $w->requiredConfirmations);
        self::assertSame(0, $w->toWire()['confirmations']);
    }

    /**
     * The 0.x positional prefix of the withdrawal constructor stays where it was; the new
     * fields only go after `error`.
     */
    public function testWithdrawalKeepsItsPositionalPrefix(): void
    {
        $ctor = (new ReflectionClass(Withdrawal::class))->getConstructor();
        self::assertNotNull($ctor);
        $names = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $ctor->getParameters()
        );

        self::assertSame(
            ['uuid', 'status', 'network', 'coin', 'contract', 'amount', 'amountFiat', 'fromAddress',
                'toAddress', 'txHash', 'createdAt', 'updatedAt', 'confirmedAt', 'error'],
            array_slice($names, 0, 14)
        );

        $w = new Withdrawal('wd-1', 'completed', 'ETH_MAINNET', 'USDT', null, '1');
        self::assertSame('1', $w->amount);
        self::assertNull($w->confirmations);
        self::assertNull($w->requiredConfirmations);
    }

    // -- Positional API -------------------------------------------------------

    /**
     * Constructor parameters are positional as well as named (see DtoParameterOrderTest),
     * so the new fields have to come after every existing one.
     */
    public function testNewFieldsAreAppendedAfterTheExistingParameters(): void
    {
        $tails = [
            PayoutSource::class => [
                'coin', 'confirmations', 'amountCrypto', 'txid', 'network', 'feePaid', 'feePaidFiat',
                'needRefuel', 'refuelAmount', 'estimatedFee', 'estimatedFeeFiat',
            ],
            PayoutInfo::class => ['error', 'serviceOperations', 'confirmations', 'requiredConfirmations'],
            TransactionInfo::class => ['error', 'confirmations', 'requiredConfirmations', 'errorReason'],
            PayoutEvent::class => ['errorReason', 'confirmations', 'requiredConfirmations'],
            TransactionEvent::class => ['errorReason', 'confirmations', 'requiredConfirmations'],
            Sweep::class => ['updatedAt', 'requiredConfirmations'],
            SweepEvent::class => ['totalFeeUsd', 'requiredConfirmations'],
            Withdrawal::class => [
                'error', 'confirmations', 'requiredConfirmations', 'needRefuel', 'refuelTxHash',
                'refuelStatus', 'errorReason', 'estimatedFeeFiat', 'actualFeeFiat', 'feeMode', 'completedAt',
            ],
        ];
        foreach ($tails as $class => $tail) {
            $ctor = (new ReflectionClass($class))->getConstructor();
            self::assertNotNull($ctor);
            $names = array_map(
                static fn (\ReflectionParameter $p): string => $p->getName(),
                $ctor->getParameters()
            );
            self::assertSame($tail, array_slice($names, -count($tail)), $class);
        }

        $s = new PayoutSource('0xa', '1', 'ETH');
        self::assertSame('ETH', $s->coin);
        self::assertNull($s->confirmations);
    }
}
