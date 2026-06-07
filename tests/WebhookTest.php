<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Exception\WebhookSignatureException;
use CryptoChief\Processing\Sign;
use CryptoChief\Processing\Webhook;
use CryptoChief\Processing\Webhook\PayoutEvent;
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
