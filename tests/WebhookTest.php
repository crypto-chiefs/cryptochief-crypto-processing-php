<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Exception\CryptoChiefException;
use CryptoChief\Processing\Exception\WebhookHeadersException;
use CryptoChief\Processing\Exception\WebhookSignatureException;
use CryptoChief\Processing\Exception\WebhookTimestampException;
use CryptoChief\Processing\Exception\WebhookVerificationException;
use CryptoChief\Processing\Sign;
use CryptoChief\Processing\Tests\Support\Vectors;
use CryptoChief\Processing\Webhook;
use CryptoChief\Processing\Webhook\PayInEvent;
use CryptoChief\Processing\Webhook\PayoutEvent;
use CryptoChief\Processing\Webhook\SweepEvent;
use CryptoChief\Processing\Webhook\TransactionEvent;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const SECRET = 'test_api_key_123';
    private const DELIVERY = '7c9e6679-7425-40de-944b-e07fc1f90ae7';
    private const NOW = 1789430400;

    /**
     * Raw body and the headers the platform sends with it, signed at `$timestamp`.
     *
     * @param array<string, mixed>|string $body
     * @return array{string, array<string, string>}
     */
    private static function signed(array|string $body, int $timestamp = self::NOW): array
    {
        $raw = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [$raw, [
            'Content-Type' => 'application/json',
            Webhook::DELIVERY_HEADER => self::DELIVERY,
            Webhook::TIMESTAMP_HEADER => (string) $timestamp,
            Webhook::SIGNATURE_HEADER => Sign::webhookV1Sign(self::SECRET, $timestamp, self::DELIVERY, $raw),
        ]];
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function parse(array $body): mixed
    {
        [$raw, $headers] = self::signed($body);

        return Webhook::parseEvent(self::SECRET, $raw, $headers, now: self::NOW);
    }

    /**
     * @param array<string, mixed> $headers
     * @param class-string<WebhookVerificationException> $expected
     */
    private static function assertRefused(string $expected, string $raw, array $headers, int $tolerance = 300, int $now = self::NOW): void
    {
        try {
            Webhook::verify(self::SECRET, $raw, $headers, $tolerance, $now);
            self::fail('expected ' . $expected);
        } catch (WebhookVerificationException $e) {
            self::assertSame($expected, $e::class, $e->getMessage());
        }
    }

    public function testHeaderConstants(): void
    {
        self::assertSame('X-Webhook-Delivery', Webhook::DELIVERY_HEADER);
        self::assertSame('X-CC-Timestamp', Webhook::TIMESTAMP_HEADER);
        self::assertSame('X-CC-Signature', Webhook::SIGNATURE_HEADER);
        self::assertSame(300, Webhook::DEFAULT_TOLERANCE);
    }

    public function testVerifyAcceptsSignedRawBody(): void
    {
        [$raw, $headers] = self::signed("{\n  \"event\": \"payout.paid\",\r\n  \"uuid\": \"abc\", \"n\": -0\n}\n");

        Webhook::verify(self::SECRET, $raw, $headers, now: self::NOW);
        $this->addToAssertionCount(1);
    }

    public function testStringToSign(): void
    {
        self::assertSame(
            "CC-HMAC-SHA256-WEBHOOK-V1\n1789430400\n" . self::DELIVERY . "\n" . hash('sha256', '{}'),
            Sign::webhookV1StringToSign(self::NOW, self::DELIVERY, '{}'),
        );
        self::assertMatchesRegularExpression('/^v1=[0-9a-f]{64}$/', Sign::webhookV1Sign(self::SECRET, self::NOW, self::DELIVERY, ''));
    }

    public function testSignRejectsBadArguments(): void
    {
        $cases = [
            static fn () => Sign::webhookV1Sign('', self::NOW, self::DELIVERY, '{}'),
            static fn () => Sign::webhookV1Sign(' ', self::NOW, self::DELIVERY, '{}'),
            static fn () => Sign::webhookV1Sign("\t", self::NOW, self::DELIVERY, '{}'),
            static fn () => Sign::webhookV1Sign(" \t ", self::NOW, self::DELIVERY, '{}'),
            static fn () => Sign::webhookV1StringToSign(0, self::DELIVERY, '{}'),
            static fn () => Sign::webhookV1StringToSign(-1, self::DELIVERY, '{}'),
            static fn () => Sign::webhookV1StringToSign(self::NOW, '', '{}'),
            static fn () => Sign::webhookV1StringToSign(self::NOW, "dlv\n", '{}'),
            static fn () => Sign::webhookV1StringToSign(self::NOW, 'dlv.1', '{}'),
            static fn () => Sign::webhookV1StringToSign(self::NOW, str_repeat('a', 129), '{}'),
        ];
        foreach ($cases as $i => $case) {
            try {
                $case();
                self::fail("case $i: expected CryptoChiefException");
            } catch (CryptoChiefException $e) {
                self::assertNotInstanceOf(WebhookVerificationException::class, $e);
            }
        }
    }

    public function testVerifyRejectsTamperedBodyAndWrongKey(): void
    {
        [$raw, $headers] = self::signed(['event' => 'payout.paid', 'uuid' => 'abc']);

        self::assertRefused(WebhookSignatureException::class, str_replace('abc', 'xyz', $raw), $headers);
        self::assertRefused(WebhookSignatureException::class, $raw . ' ', $headers);

        try {
            Webhook::verify('wrong_key', $raw, $headers, now: self::NOW);
            self::fail('expected WebhookSignatureException');
        } catch (WebhookSignatureException) {
            $this->addToAssertionCount(1);
        }
    }

    /** A key of spaces and tabs is as empty as "". */
    public function testBlankApiKeyIsNotAVerificationFailure(): void
    {
        [$raw, $headers] = self::signed(['event' => 'payout.paid']);

        foreach (['', ' ', "\t", " \t "] as $apiKey) {
            try {
                Webhook::verify($apiKey, $raw, $headers, now: self::NOW);
                self::fail('expected CryptoChiefException');
            } catch (CryptoChiefException $e) {
                self::assertNotInstanceOf(WebhookVerificationException::class, $e);
                self::assertStringContainsString('api_key', $e->getMessage());
            }
        }
    }

    public function testLegacySignatureHeadersAreNotRead(): void
    {
        $raw = '{"event":"payout.paid"}';
        $legacy = '9e107d9d372bb6826bd81d3542a419d6';

        self::assertRefused(WebhookHeadersException::class, $raw, ['Signature' => $legacy, 'X-Webhook-Signature' => $legacy]);
    }

    public function testToleranceAndClock(): void
    {
        [$raw, $headers] = self::signed('{"event":"x"}');

        Webhook::verify(self::SECRET, $raw, $headers, 60, self::NOW + 60);
        Webhook::verify(self::SECRET, $raw, $headers, 0, self::NOW - 300);
        Webhook::verify(self::SECRET, $raw, $headers, -5, self::NOW + 300);
        $this->addToAssertionCount(3);

        self::assertRefused(WebhookTimestampException::class, $raw, $headers, 60, self::NOW + 61);
        self::assertRefused(WebhookTimestampException::class, $raw, $headers, 0, self::NOW - 301);

        // Real clock: the fixed timestamp is far outside the window.
        self::assertRefused(WebhookTimestampException::class, $raw, $headers, 300, time());
        try {
            Webhook::verify(self::SECRET, $raw, $headers);
            self::fail('expected WebhookTimestampException');
        } catch (WebhookTimestampException) {
            $this->addToAssertionCount(1);
        }

        [$fresh, $freshHeaders] = self::signed('{"event":"x"}', time());
        Webhook::verify(self::SECRET, $fresh, $freshHeaders);
    }

    public function testHeaderNamesAreCaseInsensitiveAndTwoSpellingsAreARepeat(): void
    {
        [$raw, $headers] = self::signed('{"event":"x"}');
        $lower = array_change_key_case($headers, CASE_LOWER);
        Webhook::verify(self::SECRET, $raw, $lower, now: self::NOW);
        $this->addToAssertionCount(1);

        $twice = $lower + [Webhook::SIGNATURE_HEADER => $headers[Webhook::SIGNATURE_HEADER]];
        self::assertRefused(WebhookHeadersException::class, $raw, $twice);

        $psr7 = array_map(static fn (string $v): array => [$v], $headers);
        Webhook::verify(self::SECRET, $raw, $psr7, now: self::NOW);
        $this->addToAssertionCount(1);

        $timestampAsInt = [Webhook::TIMESTAMP_HEADER => self::NOW] + $headers;
        Webhook::verify(self::SECRET, $raw, $timestampAsInt, now: self::NOW);
        $this->addToAssertionCount(1);
    }

    public function testMalformedHeaderValues(): void
    {
        [$raw, $headers] = self::signed('{"event":"x"}');

        foreach (
            [
                [Webhook::TIMESTAMP_HEADER, null],
                [Webhook::TIMESTAMP_HEADER, '9223372036854775808'],
                [Webhook::TIMESTAMP_HEADER, ' 1789430400 x'],
                [Webhook::DELIVERY_HEADER, [null]],
                [Webhook::SIGNATURE_HEADER, []],
                [Webhook::SIGNATURE_HEADER, "v1=" . str_repeat('a', 64) . "\n"],
            ] as [$name, $value]
        ) {
            self::assertRefused(WebhookHeadersException::class, $raw, [$name => $value] + $headers);
        }
    }

    public function testTimestampWithLeadingZerosIsRefused(): void
    {
        [$raw, $headers] = self::signed('{"event":"x"}');

        foreach (['0' . self::NOW, '00' . self::NOW, '01', '00'] as $value) {
            self::assertRefused(
                WebhookHeadersException::class,
                $raw,
                [Webhook::TIMESTAMP_HEADER => $value] + $headers,
            );
        }

        // "0" is a decimal without a leading zero: it passes the header check and
        // falls outside the window.
        self::assertRefused(
            WebhookTimestampException::class,
            $raw,
            [Webhook::TIMESTAMP_HEADER => '0'] + $headers,
        );
    }

    public function testHeadersFromGlobals(): void
    {
        self::assertSame(
            [
                'content-type' => 'application/json',
                'x-cc-timestamp' => '1789430400',
                'x-webhook-delivery' => 'dlv',
                'host' => 'example.com',
            ],
            Webhook::headersFromGlobals([
                'REQUEST_METHOD' => 'POST',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CC_TIMESTAMP' => '1789430400',
                'HTTP_X_WEBHOOK_DELIVERY' => 'dlv',
                'HTTP_HOST' => 'example.com',
                'argv' => [],
            ]),
        );

        $saved = $_SERVER;
        try {
            $_SERVER['HTTP_X_CC_SIGNATURE'] = 'v1=abc';
            self::assertSame('v1=abc', Webhook::headersFromGlobals()['x-cc-signature'] ?? null);
        } finally {
            $_SERVER = $saved;
        }
    }

    public function testParseEventRefusesBeforeDecoding(): void
    {
        [$raw, $headers] = self::signed(['event' => 'payout.paid']);
        $headers[Webhook::SIGNATURE_HEADER] = 'v1=' . str_repeat('0', 64);

        $this->expectException(WebhookSignatureException::class);
        Webhook::parseEvent(self::SECRET, $raw, $headers, now: self::NOW);
    }

    public function testParseEventRequiresJsonObject(): void
    {
        foreach (['', '[1,2]', 'not json', "{\"event\":\"x\",\"s\":\"a\xFF\"}", '{"event":'] as $body) {
            [$raw, $headers] = self::signed($body);
            Webhook::verify(self::SECRET, $raw, $headers, now: self::NOW);
            try {
                Webhook::parseEvent(self::SECRET, $raw, $headers, now: self::NOW);
                self::fail('expected CryptoChiefException for ' . $body);
            } catch (CryptoChiefException $e) {
                self::assertNotInstanceOf(WebhookVerificationException::class, $e);
                self::assertStringContainsString('not a JSON object', $e->getMessage());
            }
        }
    }

    public function testParsePayoutEvent(): void
    {
        $event = self::parse([
            'event' => 'payout.paid',
            'uuid' => 'pay-1',
            'status' => 'paid',
            'amount_requested' => '1.5',
            'amount_to_receive' => '1.49',
            'to_address' => '0xabc',
        ]);
        self::assertInstanceOf(PayoutEvent::class, $event);
        self::assertSame('payout.paid', $event->event);
        self::assertSame('pay-1', $event->uuid);
        self::assertSame('1.5', $event->amountRequested);
        self::assertSame('1.49', $event->amountToReceive);
        self::assertSame('0xabc', $event->toAddress);
    }

    public function testParseTransactionEvent(): void
    {
        $event = self::parse([
            'event' => 'transaction.confirmed',
            'uuid' => 'tx-1',
            'status' => 'confirmed',
            'chain_family' => 'EVM',
            'from_address' => '0xfrom',
            'to_address' => '0xto',
            'value' => '1000',
        ]);
        self::assertInstanceOf(TransactionEvent::class, $event);
        self::assertSame('EVM', $event->chainFamily);
        self::assertSame('0xfrom', $event->fromAddress);
    }

    public function testParsePayoutEventCarriesConfirmations(): void
    {
        $event = self::parse([
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
        ]);
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
        $event = self::parse([
            'event' => 'payout.system_fail',
            'uuid' => 'pay-2',
            'status' => 'system_fail',
            'sources' => [['address' => '0xa', 'coin' => 'ETH']],
            'service_operations' => [],
            'error_reason' => 'insufficient gas',
        ]);
        self::assertInstanceOf(PayoutEvent::class, $event);
        self::assertNull($event->confirmations);
        self::assertNull($event->requiredConfirmations);
        self::assertIsArray($event->sources);
        self::assertArrayNotHasKey('confirmations', $event->sources[0]);
        self::assertSame('insufficient gas', $event->errorReason);
    }

    public function testParseTransactionEventCarriesConfirmations(): void
    {
        $event = self::parse([
            'event' => 'transaction.confirmed',
            'uuid' => 'tx-1',
            'status' => 'confirmed',
            'tx_hash' => '0xhash',
            'confirmations' => 12,
            'required_confirmations' => 12,
        ]);
        self::assertInstanceOf(TransactionEvent::class, $event);
        self::assertSame(12, $event->confirmations);
        self::assertSame(12, $event->requiredConfirmations);

        // An expired transaction never confirmed: a real 0 against the network's threshold.
        $event = self::parse([
            'event' => 'transaction.expired',
            'uuid' => 'tx-2',
            'status' => 'expired',
            'confirmations' => 0,
            'required_confirmations' => 19,
        ]);
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
        $event = self::parse(self::sweepBody() + ['required_confirmations' => 32]);
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
        $event = self::parse(['sweep_confirmations' => 1] + self::sweepBody());
        self::assertInstanceOf(SweepEvent::class, $event);
        self::assertSame(1, $event->sweepConfirmations);
        self::assertNull($event->requiredConfirmations);
        self::assertSame('0xsweep', $event->sweepTxHash);
    }

    public function testParsePayInEventFromVectorBody(): void
    {
        foreach (Vectors::webhook() as $v) {
            if ($v['name'] !== 'payin_invoice_paid_with_nulls') {
                continue;
            }
            $event = Webhook::parseEvent($v['api_key'], $v['body'], [
                'x-cc-timestamp' => (string) $v['timestamp'],
                'x-webhook-delivery' => $v['delivery_id'],
                'x-cc-signature' => $v['signature'],
            ], now: $v['now']);
            self::assertInstanceOf(PayInEvent::class, $event);
            self::assertStringStartsWith('invoice.', $event->event);

            return;
        }
        self::fail('no vector payin_invoice_paid_with_nulls');
    }

    public function testUnknownEventReturnsRawArray(): void
    {
        $event = self::parse(['event' => 'whatever.unknown', 'foo' => 'bar']);
        self::assertIsArray($event);
        self::assertSame('whatever.unknown', $event['event']);
    }
}
