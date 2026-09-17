<?php

declare(strict_types=1);

namespace CryptoChief\Processing;

use CryptoChief\Processing\Exception\CryptoChiefException;

/**
 * HMAC-SHA256 v1 signatures of API requests and webhooks.
 *
 * Both sign the exact body bytes.
 */
final class Sign
{
    public const HEADER_MERCHANT = 'Merchant';
    public const HEADER_TIMESTAMP = 'X-CC-Timestamp';
    public const HEADER_NONCE = 'X-CC-Nonce';
    public const HEADER_SIGNATURE = 'X-CC-Signature';
    public const HEADER_IDEMPOTENCY_KEY = 'Idempotency-Key';

    /** First line of the request string to sign. */
    public const HMAC_V1_SCOPE = 'CC-HMAC-SHA256-REQ-V1';

    /** First line of the webhook string to sign. */
    public const WEBHOOK_V1_SCOPE = 'CC-HMAC-SHA256-WEBHOOK-V1';

    /** Prefix of the X-CC-Signature value. */
    public const HMAC_V1_PREFIX = 'v1=';

    /**
     * Request string to sign:
     *
     *   CC-HMAC-SHA256-REQ-V1\n<timestamp>\n<nonce>\n<METHOD>\n<path>\n<query>\n<merchant>\n<idempotency_key>\n<hex(sha256(body))>
     *
     * `path` is the percent-decoded route path from `/v1/` (`/v1/a%20b` is signed as
     * `/v1/a b`), `query` is without `?` as sent, `body` is the exact bytes sent. The method
     * is signed in upper case by {@see self::upperMethod()}. A field containing CR or LF
     * throws.
     */
    public static function hmacV1StringToSign(
        string $timestamp,
        string $nonce,
        string $method,
        string $path,
        string $query,
        string $merchant,
        string $idempotencyKey,
        string $body,
    ): string {
        $fields = [
            $timestamp,
            $nonce,
            self::upperMethod($method),
            $path,
            $query,
            $merchant,
            $idempotencyKey,
        ];
        foreach ($fields as $field) {
            if (strpbrk($field, "\r\n") !== false) {
                throw new CryptoChiefException('cryptochief: hmac v1 field contains CR or LF');
            }
        }

        return self::HMAC_V1_SCOPE . "\n" . implode("\n", $fields) . "\n" . self::hmacV1BodySha256($body);
    }

    /**
     * The HTTP method as it is signed: `a`-`z` raised to upper case, every other byte as it
     * is. The method is an RFC 9110 token, and a Unicode mapping would rewrite bytes the
     * server keeps.
     */
    public static function upperMethod(string $method): string
    {
        return strtr($method, 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');
    }

    /** An api_key that is empty or only spaces and tabs signs and verifies nothing. */
    public static function isBlankApiKey(string $apiKey): bool
    {
        return trim($apiKey, " \t") === '';
    }

    /**
     * Request signature, without the `v1=` prefix:
     *
     *   hex(hmac_sha256(key = api_key, message = hmacV1StringToSign(...)))
     *
     * Throws CryptoChiefException on a blank `$apiKey` and on the arguments
     * hmacV1StringToSign() rejects.
     */
    public static function hmacV1Sign(
        string $apiKey,
        string $timestamp,
        string $nonce,
        string $method,
        string $path,
        string $query,
        string $merchant,
        string $idempotencyKey,
        string $body,
    ): string {
        if (self::isBlankApiKey($apiKey)) {
            throw new CryptoChiefException('cryptochief: api_key is required to sign a request');
        }

        return hash_hmac('sha256', self::hmacV1StringToSign(
            $timestamp,
            $nonce,
            $method,
            $path,
            $query,
            $merchant,
            $idempotencyKey,
            $body,
        ), $apiKey);
    }

    /** Lowercase hex SHA-256 of the body bytes. */
    public static function hmacV1BodySha256(string $body): string
    {
        return hash('sha256', $body);
    }

    /** X-CC-Nonce value: 32 hex characters from 16 random bytes. */
    public static function hmacV1Nonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Webhook string to sign:
     *
     *   CC-HMAC-SHA256-WEBHOOK-V1\n<timestamp>\n<delivery_id>\n<hex(sha256(raw_body))>
     *
     * Throws CryptoChiefException when `$timestamp` is not positive or `$deliveryId` is not
     * 1-128 characters `[A-Za-z0-9_-]`.
     */
    public static function webhookV1StringToSign(int $timestamp, string $deliveryId, string $rawBody): string
    {
        if ($timestamp <= 0) {
            throw new CryptoChiefException('cryptochief: webhook timestamp must be positive');
        }
        if (preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $deliveryId) !== 1) {
            throw new CryptoChiefException('cryptochief: webhook delivery id must be 1-128 characters [A-Za-z0-9_-]');
        }

        return self::WEBHOOK_V1_SCOPE . "\n" . $timestamp . "\n" . $deliveryId . "\n" . hash('sha256', $rawBody);
    }

    /**
     * Webhook X-CC-Signature value, with the `v1=` prefix:
     *
     *   "v1=" . hex(hmac_sha256(key = api_key, message = webhookV1StringToSign(...)))
     *
     * Throws CryptoChiefException on a blank `$apiKey` and on the arguments
     * webhookV1StringToSign() rejects.
     */
    public static function webhookV1Sign(string $apiKey, int $timestamp, string $deliveryId, string $rawBody): string
    {
        if (self::isBlankApiKey($apiKey)) {
            throw new CryptoChiefException('cryptochief: api_key is required to sign a webhook');
        }

        return self::HMAC_V1_PREFIX
            . hash_hmac('sha256', self::webhookV1StringToSign($timestamp, $deliveryId, $rawBody), $apiKey);
    }
}
