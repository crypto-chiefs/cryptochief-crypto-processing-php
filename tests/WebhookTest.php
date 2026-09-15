<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Exception\WebhookSignatureException;
use CryptoChief\Processing\Sign;
use CryptoChief\Processing\Webhook;
use CryptoChief\Processing\Webhook\PayoutEvent;
use CryptoChief\Processing\Webhook\SweepEvent;
use CryptoChief\Processing\Webhook\TransactionEvent;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const SECRET = 'test_api_key_123';

    public function testVerifyValid(): void
    {
        $body = ['event' => 'payout.paid', 'uuid' => 'abc', 'status' => 'paid', 'amount_requested' => '1.5'];
        $canonical = Sign::canonicalJson($body);
        $sig = Sign::sign($canonical, self::SECRET);
        self::assertTrue(Webhook::verify(self::SECRET, $canonical, $sig));
    }

    public function testVerifyToleratesUnsortedKeys(): void
    {
        // Body order doesn't matter - the canonical form is re-derived.
        $body = ['uuid' => 'abc', 'event' => 'payout.paid', 'status' => 'paid'];
        $canonical = Sign::canonicalJson($body);
        $sig = Sign::sign($canonical, self::SECRET);
        $unsorted = '{"uuid":"abc","event":"payout.paid","status":"paid"}';
        self::assertTrue(Webhook::verify(self::SECRET, $unsorted, $sig));
    }

    public function testVerifyRejectsTamperedBody(): void
    {
        $body = ['event' => 'payout.paid', 'uuid' => 'abc', 'status' => 'paid'];
        $canonical = Sign::canonicalJson($body);
        $sig = Sign::sign($canonical, self::SECRET);
        $tampered = str_replace('abc', 'xyz', $canonical);
        self::assertFalse(Webhook::verify(self::SECRET, $tampered, $sig));
    }

    public function testVerifyRejectsBadSignature(): void
    {
        $canonical = Sign::canonicalJson(['event' => 'payout.paid']);
        self::assertFalse(Webhook::verify(self::SECRET, $canonical, 'deadbeef'));
    }

    public function testVerifyRejectsEmptyBody(): void
    {
        self::assertFalse(Webhook::verify(self::SECRET, '', 'whatever'));
    }

    public function testParsePayoutEvent(): void
    {
        $body = [
            'event' => 'payout.paid',
            'uuid' => 'pay-1',
            'status' => 'paid',
            'amount_requested' => '1.5',
            'amount_to_receive' => '1.49',
            'to_address' => '0xabc',
        ];
        $canonical = Sign::canonicalJson($body);
        $sig = Sign::sign($canonical, self::SECRET);
        $event = Webhook::parseEvent(self::SECRET, $canonical, $sig);
        self::assertInstanceOf(PayoutEvent::class, $event);
        self::assertSame('payout.paid', $event->event);
        self::assertSame('pay-1', $event->uuid);
        self::assertSame('1.5', $event->amountRequested);
        self::assertSame('1.49', $event->amountToReceive);
        self::assertSame('0xabc', $event->toAddress);
    }

    public function testParseTransactionEvent(): void
    {
        $body = [
            'event' => 'transaction.confirmed',
            'uuid' => 'tx-1',
            'status' => 'confirmed',
            'chain_family' => 'EVM',
            'from_address' => '0xfrom',
            'to_address' => '0xto',
            'value' => '1000',
        ];
        $canonical = Sign::canonicalJson($body);
        $sig = Sign::sign($canonical, self::SECRET);
        $event = Webhook::parseEvent(self::SECRET, $canonical, $sig);
        self::assertInstanceOf(TransactionEvent::class, $event);
        self::assertSame('EVM', $event->chainFamily);
        self::assertSame('0xfrom', $event->fromAddress);
    }

    public function testParsePayoutEventCarriesConfirmations(): void
    {
        $body = [
            'event' => 'payout.paid',
            'uuid' => 'pay-1',
            'status' => 'paid',
            'sources' => [
                ['address' => '0xa', 'coin' => 'ETH', 'txid' => '0x1', 'confirmations' => 40],
                ['address' => '0xb', 'coin' => 'ETH', 'txid' => '0x2', 'confirmations' => 32],
            ],
            'service_operations' => [
                ['type' => 'gas_refuel', 'status' => 'done', 'txid' => '0x3', 'confirmations' => 20],
            ],
            'confirmations' => 32,
            'required_confirmations' => 32,
        ];
        $canonical = Sign::canonicalJson($body);
        $sig = Sign::sign($canonical, self::SECRET);
        $event = Webhook::parseEvent(self::SECRET, $canonical, $sig);
        self::assertInstanceOf(PayoutEvent::class, $event);
        // payout.paid: every source reached the depth, so the lowest is at least it.
        self::assertSame(32, $event->confirmations);
        self::assertSame(32, $event->requiredConfirmations);
        self::assertIsArray($event->sources);
        self::assertSame(40, $event->sources[0]['confirmations']);
        self::assertSame(32, $event->sources[1]['confirmations']);
        self::assertIsArray($event->serviceOperations);
        self::assertSame(20, $event->serviceOperations[0]['confirmations']);
    }

    public function testParsePayoutEventWithoutConfirmations(): void
    {
        // A payout that failed before sending anything: no source has a transaction, so
        // the platform omits every count rather than sending 0. Recorded before the
        // platform kept the finality depth, it carries no required_confirmations either.
        $body = [
            'event' => 'payout.system_fail',
            'uuid' => 'pay-2',
            'status' => 'system_fail',
            'sources' => [['address' => '0xa', 'coin' => 'ETH']],
            'service_operations' => [],
            'error_reason' => 'insufficient gas',
        ];
        $canonical = Sign::canonicalJson($body);
        $sig = Sign::sign($canonical, self::SECRET);
        $event = Webhook::parseEvent(self::SECRET, $canonical, $sig);
        self::assertInstanceOf(PayoutEvent::class, $event);
        self::assertNull($event->confirmations);
        self::assertNull($event->requiredConfirmations);
        self::assertIsArray($event->sources);
        self::assertArrayNotHasKey('confirmations', $event->sources[0]);
        self::assertSame('insufficient gas', $event->errorReason);
    }

    public function testParseTransactionEventCarriesConfirmations(): void
    {
        $body = [
            'event' => 'transaction.confirmed',
            'uuid' => 'tx-1',
            'status' => 'confirmed',
            'tx_hash' => '0xhash',
            'confirmations' => 12,
            'required_confirmations' => 12,
        ];
        $canonical = Sign::canonicalJson($body);
        $sig = Sign::sign($canonical, self::SECRET);
        $event = Webhook::parseEvent(self::SECRET, $canonical, $sig);
        self::assertInstanceOf(TransactionEvent::class, $event);
        self::assertSame(12, $event->confirmations);
        self::assertSame(12, $event->requiredConfirmations);

        // An expired transaction never confirmed: a real 0 against the network's threshold.
        $body = [
            'event' => 'transaction.expired',
            'uuid' => 'tx-2',
            'status' => 'expired',
            'confirmations' => 0,
            'required_confirmations' => 19,
        ];
        $canonical = Sign::canonicalJson($body);
        $sig = Sign::sign($canonical, self::SECRET);
        $event = Webhook::parseEvent(self::SECRET, $canonical, $sig);
        self::assertInstanceOf(TransactionEvent::class, $event);
        self::assertSame(0, $event->confirmations);
        self::assertSame(19, $event->requiredConfirmations);
    }

    /**
     * @return array<string, mixed>
     */
    private static function sweepBody(): array
    {
        return [
            'event' => 'sweep.confirmed',
            'task_id' => 'task-1',
            'status' => 'completed',
            'wallet_address' => '0xdeposit',
            'to_address' => '0xmaster',
            'network' => 'ETH_MAINNET',
            'asset_symbol' => 'USDT',
            'asset_type' => 'token',
            'amount_human' => '125.5',
            'sweep_tx_hash' => '0xsweep',
            'sweep_confirmations' => 33,
            'confirmed_at' => '2026-09-14T10:05:00Z',
            'type_work' => 'threshold',
            'total_fee_usd' => '0.8100',
        ];
    }

    public function testParseSweepEventCarriesRequiredConfirmations(): void
    {
        $body = self::sweepBody() + ['required_confirmations' => 32];
        $canonical = Sign::canonicalJson($body);
        $sig = Sign::sign($canonical, self::SECRET);
        $event = Webhook::parseEvent(self::SECRET, $canonical, $sig);
        self::assertInstanceOf(SweepEvent::class, $event);
        self::assertSame('task-1', $event->taskId);
        self::assertSame('completed', $event->status);
        self::assertSame(33, $event->sweepConfirmations);
        self::assertSame(32, $event->requiredConfirmations);
        self::assertGreaterThanOrEqual($event->requiredConfirmations, $event->sweepConfirmations);
        self::assertSame('0.8100', $event->totalFeeUsd);
    }

    public function testParseSweepEventFromAnOlderSweepServiceHasNoRequiredConfirmations(): void
    {
        // An event reported by a sweep service built before sweeps waited for finality:
        // sent on the first block, with no depth. It must still parse.
        $body = ['sweep_confirmations' => 1] + self::sweepBody();
        $canonical = Sign::canonicalJson($body);
        $sig = Sign::sign($canonical, self::SECRET);
        $event = Webhook::parseEvent(self::SECRET, $canonical, $sig);
        self::assertInstanceOf(SweepEvent::class, $event);
        self::assertSame(1, $event->sweepConfirmations);
        self::assertNull($event->requiredConfirmations);
        self::assertSame('0xsweep', $event->sweepTxHash);
    }

    public function testParseRejectsBadSignature(): void
    {
        $this->expectException(WebhookSignatureException::class);
        Webhook::parseEvent(self::SECRET, '{"event":"payout.paid"}', 'bad');
    }

    public function testUnknownEventReturnsRawArray(): void
    {
        $body = ['event' => 'whatever.unknown', 'foo' => 'bar'];
        $canonical = Sign::canonicalJson($body);
        $sig = Sign::sign($canonical, self::SECRET);
        $event = Webhook::parseEvent(self::SECRET, $canonical, $sig);
        self::assertIsArray($event);
        self::assertSame('whatever.unknown', $event['event']);
    }
}
