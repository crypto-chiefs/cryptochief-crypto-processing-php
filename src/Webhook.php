<?php

declare(strict_types=1);

namespace CryptoChief\Processing;

use CryptoChief\Processing\Exception\CryptoChiefException;
use CryptoChief\Processing\Exception\WebhookSignatureException;
use CryptoChief\Processing\Webhook\PayInEvent;
use CryptoChief\Processing\Webhook\PayoutEvent;
use CryptoChief\Processing\Webhook\StaticDepositEvent;
use CryptoChief\Processing\Webhook\SweepEvent;
use CryptoChief\Processing\Webhook\TransactionEvent;

/**
 * Webhook verification and typed event parsing.
 *
 * Signature: `hex(md5(base64(canonical_json(body)) + api_key))`. The body is
 * re-canonicalized before hashing so any key-order drift is normalized. Feed in the raw
 * request bytes and the `Signature` header.
 */
final class Webhook
{
    /** Header name carrying the webhook signature. */
    public const HEADER = 'Signature';

    /**
     * Header carrying the delivery's uuid on every webhook the platform sends.
     * Constant across every attempt and resend of one delivery — use it as your
     * receiver's idempotency key — and the argument `$client->webhooks()->info()`
     * / `resend()` take. Keep it when you log an incoming webhook: there is no
     * other way to name a delivery later.
     */
    public const DELIVERY_HEADER = 'X-Webhook-Delivery';

    /** IP addresses Crypto Chief delivers webhooks from. */
    public const SENDER_IPS = ['164.90.231.203', '104.248.248.64'];

    /**
     * Verify an incoming webhook against the merchant API key. `$rawBody` MUST be the
     * exact bytes received. Comparison is constant-time.
     */
    public static function verify(string $apiKey, string $rawBody, ?string $signature): bool
    {
        if ($apiKey === '') {
            throw new CryptoChiefException('cryptochief: api_key is required for webhook verification');
        }
        if ($rawBody === '' || $signature === null || $signature === '') {
            return false;
        }
        try {
            $parsed = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        if (!is_array($parsed)) {
            return false;
        }
        $expected = Sign::sign(Sign::canonicalJson($parsed), $apiKey);
        return hash_equals($expected, $signature);
    }

    /**
     * Verify and parse a webhook. Throws WebhookSignatureException on an invalid signature.
     * Returns the typed event chosen by the `event` name prefix, or the raw associative
     * array for an unrecognized prefix.
     *
     * @return PayoutEvent|TransactionEvent|PayInEvent|StaticDepositEvent|SweepEvent|array<string, mixed>
     */
    public static function parseEvent(string $apiKey, string $rawBody, ?string $signature): mixed
    {
        if (!self::verify($apiKey, $rawBody, $signature)) {
            throw new WebhookSignatureException();
        }
        /** @var array<string, mixed> $data */
        $data = json_decode($rawBody, true);
        return self::coerceEvent($data);
    }

    /**
     * Map a parsed webhook array to its typed event by the `event` prefix.
     *
     * @param array<string, mixed> $data
     * @return PayoutEvent|TransactionEvent|PayInEvent|StaticDepositEvent|SweepEvent|array<string, mixed>
     */
    public static function coerceEvent(array $data): mixed
    {
        $event = isset($data['event']) && is_string($data['event']) ? $data['event'] : '';
        $prefix = explode('.', $event)[0] ?? '';
        return match ($prefix) {
            'payout'         => PayoutEvent::fromWire($data),
            'transaction'    => TransactionEvent::fromWire($data),
            'invoice'        => PayInEvent::fromWire($data),
            'static_deposit' => StaticDepositEvent::fromWire($data),
            'sweep' => SweepEvent::fromWire($data),
            default          => $data,
        };
    }
}
