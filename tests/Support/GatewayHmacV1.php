<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests\Support;

use CryptoChief\Processing\Exception\CryptoChiefException;
use CryptoChief\Processing\Sign;

/**
 * The verifying side of HMAC v1, by the rules the gateway applies.
 *
 * Used by the vector test and by the gateway mock (tests/testdata/gateway_mock_server.php),
 * so both answer one implementation. Replay protection is the caller's: this class does not
 * remember nonces.
 */
final class GatewayHmacV1
{
    public const OK = 'ok';
    public const BAD_AUTH_HEADERS = 'bad_auth_headers';
    public const TIMESTAMP_OUT_OF_RANGE = 'timestamp_out_of_range';
    public const INVALID_SIGNATURE = 'invalid_signature';

    /** Allowed distance between X-CC-Timestamp and the server clock, seconds. */
    public const WINDOW = 300;

    /** Longest accepted X-CC-Timestamp, digits. */
    private const TIMESTAMP_MAX_LEN = 18;

    private function __construct(
        public readonly string $outcome,
        public readonly string $merchant = '',
        public readonly string $nonce = '',
        public readonly string $idempotencyKey = '',
    ) {
    }

    /**
     * Check a signed request: header shape, then the timestamp window, then the signature.
     *
     * `$headers` maps a lowercase header name to its values, in the order received. `$path`
     * is percent-decoded and `$query` raw, as the server reads them. `$apiKey` empty or only
     * spaces and tabs is refused like a project without a key. `$expectedMerchant`, when
     * given, stands in for the project lookup: another merchant is an unknown project.
     *
     * @param array<string, list<string>> $headers
     */
    public static function check(
        string $apiKey,
        string $method,
        string $path,
        string $query,
        array $headers,
        string $body,
        int $now,
        ?string $expectedMerchant = null,
    ): self {
        $single = static function (string $name) use ($headers): string|false {
            $values = $headers[strtolower($name)] ?? [];
            if (count($values) > 1) {
                return false;
            }

            return $values === [] ? '' : trim($values[0], " \t");
        };

        $merchant = $single(Sign::HEADER_MERCHANT);
        $timestamp = $single(Sign::HEADER_TIMESTAMP);
        $nonce = $single(Sign::HEADER_NONCE);
        $signature = $single(Sign::HEADER_SIGNATURE);
        $idempotencyKey = $single(Sign::HEADER_IDEMPOTENCY_KEY);
        if ($merchant === false || $timestamp === false || $nonce === false || $signature === false || $idempotencyKey === false) {
            return new self(self::BAD_AUTH_HEADERS);
        }

        if (
            $merchant === ''
            || preg_match('/\A(?:0|[1-9][0-9]{0,' . (self::TIMESTAMP_MAX_LEN - 1) . '})\z/', $timestamp) !== 1
            || preg_match('/\A[A-Za-z0-9_-]{16,64}\z/', $nonce) !== 1
            || preg_match('/\Av1=[0-9A-Fa-f]{64}\z/', $signature) !== 1
        ) {
            return new self(self::BAD_AUTH_HEADERS);
        }

        if ($body !== '' && !self::isJsonContentType($headers['content-type'][0] ?? '')) {
            return new self(self::BAD_AUTH_HEADERS);
        }

        try {
            $stringToSign = Sign::hmacV1StringToSign(
                timestamp: $timestamp,
                nonce: $nonce,
                method: $method,
                path: $path,
                query: $query,
                merchant: $merchant,
                idempotencyKey: $idempotencyKey,
                body: $body,
            );
        } catch (CryptoChiefException) {
            return new self(self::BAD_AUTH_HEADERS);
        }

        if (abs($now - (int) $timestamp) > self::WINDOW) {
            return new self(self::TIMESTAMP_OUT_OF_RANGE, $merchant, $nonce, $idempotencyKey);
        }

        if (
            Sign::isBlankApiKey($apiKey)
            || ($expectedMerchant !== null && $merchant !== $expectedMerchant)
            || !hash_equals(hash_hmac('sha256', $stringToSign, $apiKey), strtolower(substr($signature, 3)))
        ) {
            return new self(self::INVALID_SIGNATURE, $merchant, $nonce, $idempotencyKey);
        }

        return new self(self::OK, $merchant, $nonce, $idempotencyKey);
    }

    /**
     * Media type `application/json` in any ASCII case, with parameters that parse.
     */
    public static function isJsonContentType(string $value): bool
    {
        $semicolon = strpos($value, ';');
        $base = $semicolon === false ? $value : substr($value, 0, $semicolon);
        if (strcasecmp(trim($base, " \t"), 'application/json') !== 0) {
            return false;
        }
        if ($semicolon === false) {
            return true;
        }

        $token = "[!#$%&'*+.^_`|~0-9A-Za-z-]+";
        foreach (explode(';', substr($value, $semicolon + 1)) as $parameter) {
            $parameter = trim($parameter, " \t");
            if (preg_match('/\A' . $token . '=(?:' . $token . '|"(?:[^"\\\\]|\\\\.)*")\z/', $parameter) !== 1) {
                return false;
            }
        }

        return true;
    }
}
