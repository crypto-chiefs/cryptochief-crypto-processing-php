<?php

declare(strict_types=1);

namespace CryptoChief\Processing;

use CryptoChief\Processing\Exception\CryptoChiefException;
use CryptoChief\Processing\Exception\WebhookHeadersException;
use CryptoChief\Processing\Exception\WebhookSignatureException;
use CryptoChief\Processing\Exception\WebhookTimestampException;
use CryptoChief\Processing\Webhook\PayInEvent;
use CryptoChief\Processing\Webhook\PayoutEvent;
use CryptoChief\Processing\Webhook\StaticDepositEvent;
use CryptoChief\Processing\Webhook\SweepEvent;
use CryptoChief\Processing\Webhook\TransactionEvent;

/**
 * Webhook verification (HMAC-SHA256 v1) and typed event parsing.
 *
 *   X-CC-Signature = "v1=" . hex(hmac_sha256(api_key,
 *       "CC-HMAC-SHA256-WEBHOOK-V1\n" . X-CC-Timestamp . "\n" . X-Webhook-Delivery . "\n" . hex(sha256(raw_body))))
 *
 * Verification takes the raw body bytes as received, before any JSON decoding.
 */
final class Webhook
{
    /**
     * Delivery id: 1-128 characters `[A-Za-z0-9_-]`, the same on every attempt and resend
     * of one delivery. Use it as the receiver's idempotency key; `$client->webhooks()->info()`
     * and `resend()` take it.
     */
    public const DELIVERY_HEADER = 'X-Webhook-Delivery';

    /** Unix time of this attempt's signature, seconds. */
    public const TIMESTAMP_HEADER = 'X-CC-Timestamp';

    /** `v1=<64 hex>`. */
    public const SIGNATURE_HEADER = 'X-CC-Signature';

    /** Default allowed distance between X-CC-Timestamp and the receiver's clock, seconds. */
    public const DEFAULT_TOLERANCE = 300;

    /** IP addresses Crypto Chief delivers webhooks from. */
    public const SENDER_IPS = ['164.90.231.203', '104.248.248.64'];

    /**
     * Verify a webhook against the merchant API key.
     *
     * `$rawBody` is the exact bytes received. `$headers` maps header names (any case) to a
     * value or a list of values: PSR-7 `getHeaders()`, Symfony `$request->headers->all()`,
     * or `headersFromGlobals()`. A header given twice, under one name or two spellings of
     * it, is refused. Values are trimmed of spaces and tabs only.
     *
     * Checks, in order:
     *   - headers present once and well-formed, `X-CC-Timestamp` decimal without leading
     *     zeros, else WebhookHeadersException;
     *   - |now - X-CC-Timestamp| <= `$tolerance` seconds (`$tolerance` <= 0 means 300),
     *     else WebhookTimestampException;
     *   - signature, compared in constant time with hex in any case, else
     *     WebhookSignatureException.
     *
     * `$now` is Unix seconds, current time when null. An `$apiKey` that is empty or only
     * spaces and tabs throws CryptoChiefException before the headers are read — a
     * configuration error, not one of the three verification failures.
     *
     * @param array<array-key, mixed> $headers
     */
    public static function verify(
        string $apiKey,
        string $rawBody,
        array $headers,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): void {
        if (Sign::isBlankApiKey($apiKey)) {
            throw new CryptoChiefException('cryptochief: api_key is required for webhook verification');
        }

        $timestampValue = self::singleHeader($headers, self::TIMESTAMP_HEADER);
        if ($timestampValue === null || preg_match('/\A(?:0|[1-9][0-9]*)\z/', $timestampValue) !== 1) {
            throw new WebhookHeadersException('cryptochief: bad X-CC-Timestamp header');
        }
        $timestamp = self::parseDecimal($timestampValue);
        if ($timestamp === null) {
            throw new WebhookHeadersException('cryptochief: bad X-CC-Timestamp header');
        }

        $deliveryId = self::singleHeader($headers, self::DELIVERY_HEADER);
        if ($deliveryId === null || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $deliveryId) !== 1) {
            throw new WebhookHeadersException('cryptochief: bad X-Webhook-Delivery header');
        }

        $signature = self::singleHeader($headers, self::SIGNATURE_HEADER);
        if ($signature === null || preg_match('/\Av1=[0-9A-Fa-f]{64}\z/', $signature) !== 1) {
            throw new WebhookHeadersException('cryptochief: bad X-CC-Signature header');
        }

        if ($tolerance <= 0) {
            $tolerance = self::DEFAULT_TOLERANCE;
        }
        $now ??= time();
        if ($timestamp < $now - $tolerance || $timestamp > $now + $tolerance) {
            throw new WebhookTimestampException();
        }

        try {
            $expected = Sign::webhookV1Sign($apiKey, $timestamp, $deliveryId, $rawBody);
        } catch (CryptoChiefException) {
            throw new WebhookHeadersException('cryptochief: bad X-CC-Timestamp header');
        }
        if (!hash_equals($expected, Sign::HMAC_V1_PREFIX . strtolower(substr($signature, 3)))) {
            throw new WebhookSignatureException();
        }
    }

    /**
     * Verify a webhook with verify(), then parse it. Returns the typed event chosen by the
     * `event` name prefix, or the decoded associative array for an unrecognized prefix.
     * Throws a WebhookVerificationException subclass when verification fails and
     * CryptoChiefException when the body is not a JSON object.
     *
     * @param array<array-key, mixed> $headers
     * @return PayoutEvent|TransactionEvent|PayInEvent|StaticDepositEvent|SweepEvent|array<string, mixed>
     */
    public static function parseEvent(
        string $apiKey,
        string $rawBody,
        array $headers,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): mixed {
        self::verify($apiKey, $rawBody, $headers, $tolerance, $now);

        $data = null;
        if (str_starts_with(ltrim($rawBody, " \t\r\n"), '{')) {
            try {
                $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $data = null;
            }
        }
        if (!is_array($data)) {
            throw new CryptoChiefException('cryptochief: webhook body is not a JSON object');
        }
        /** @var array<string, mixed> $data */
        return self::coerceEvent($data);
    }

    /**
     * Request headers keyed by lowercase name.
     *
     * Without `$server`: `getallheaders()` when the SAPI provides it, otherwise `$_SERVER`.
     * Names from `getallheaders()` that differ only in case are one header with a list of
     * values, which verify() refuses as a repeat.
     *
     * With `$server` (an array shaped like `$_SERVER`), and on the `$_SERVER` fallback:
     * `HTTP_*`, `CONTENT_TYPE` and `CONTENT_LENGTH` (`HTTP_X_CC_TIMESTAMP` -> `x-cc-timestamp`).
     *
     * Headers passed to PHP as CGI variables (`$_SERVER`; `getallheaders()` under FPM and CGI)
     * do not distinguish `_` from `-` in a name: `X_CC_Timestamp` arrives as `X-CC-Timestamp`,
     * and of two such spellings only one reaches PHP.
     *
     * @param array<array-key, mixed>|null $server
     * @return array<string, string|list<string>>
     */
    public static function headersFromGlobals(?array $server = null): array
    {
        if ($server === null && function_exists('getallheaders')) {
            $all = getallheaders();
            if (is_array($all)) {
                return self::lowercaseHeaders($all);
            }
        }

        $server ??= $_SERVER;
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $key = substr($key, 5);
            } elseif ($key !== 'CONTENT_TYPE' && $key !== 'CONTENT_LENGTH') {
                continue;
            }
            $headers[strtolower(str_replace('_', '-', $key))] = $value;
        }

        return $headers;
    }

    /**
     * Header map keyed by lowercase name. Names that differ only in case are one header and
     * keep their values as a list, which verify() refuses as a repeat. The values are not
     * read here: `getallheaders()` under the built-in server returns a broken string for a
     * header sent under two spellings, and reading it aborts the process.
     *
     * @param array<array-key, mixed> $all
     * @return array<string, string|list<string>>
     */
    private static function lowercaseHeaders(array $all): array
    {
        $headers = [];
        foreach ($all as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                continue;
            }
            $name = strtolower($name);
            if (!array_key_exists($name, $headers)) {
                $headers[$name] = $value;
                continue;
            }
            $current = $headers[$name];
            $headers[$name] = is_array($current) ? [...$current, $value] : [$current, $value];
        }

        return $headers;
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
            'sweep'          => SweepEvent::fromWire($data),
            default          => $data,
        };
    }

    /**
     * The one value of a header, trimmed of spaces and tabs. Null when the header is absent,
     * repeated, not a string or integer, or contains CR or LF.
     *
     * @param array<array-key, mixed> $headers
     */
    private static function singleHeader(array $headers, string $name): ?string
    {
        $values = [];
        foreach ($headers as $key => $value) {
            if (!is_string($key) || strcasecmp($key, $name) !== 0) {
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    $values[] = $item;
                }
            } else {
                $values[] = $value;
            }
        }
        if (count($values) !== 1) {
            return null;
        }
        $value = $values[0];
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value, " \t");

        return strpbrk($value, "\r\n") === false ? $value : null;
    }

    /** Decimal digits as int; null when the value exceeds PHP_INT_MAX. */
    private static function parseDecimal(string $digits): ?int
    {
        $max = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            return null;
        }

        return (int) $digits;
    }
}
