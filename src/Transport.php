<?php

declare(strict_types=1);

namespace CryptoChief\Processing;

use CryptoChief\Processing\Exception\ApiException;
use CryptoChief\Processing\Exception\CryptoChiefException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Psr\Http\Client\ClientInterface as PsrHttpClient;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Signed transport.
 *
 * The body is encoded to JSON once and signed with HMAC v1 (`X-CC-Timestamp`, `X-CC-Nonce`,
 * `X-CC-Signature`) over the bytes sent. The headers are computed on every attempt. 5xx
 * responses and network errors retry with exponential backoff + full jitter; 4xx is never
 * retried. `SIGNATURE_TIMESTAMP_OUT_OF_RANGE` with
 * `server_time` sets the clock offset once and resends the request immediately, outside the
 * retry budget.
 */
final class Transport
{
    private const MAX_RAW_IN_ERROR = 512;

    /**
     * An `Idempotency-Key` that can be sent as it is: printable ASCII with no space or tab
     * at either edge. The server trims those before signing, so an untrimmed value would be
     * signed in a form it never sees.
     */
    private const IDEMPOTENCY_KEY_RE = '/\A[\x21-\x7e]+(?: +[\x21-\x7e]+)*\z/';

    /** Seconds added to the local clock for X-CC-Timestamp. */
    private int $clockOffset = 0;

    public function __construct(
        private readonly string $merchantId,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $userAgent,
        private readonly PsrHttpClient $http,
        private readonly int $retries = 3,
        private readonly float $baseMs = 200.0,
        private readonly float $maxMs = 5000.0,
        float $timeoutSec = 60.0,
    ) {
        // $timeoutSec is configured on the HTTP client by the caller; accepted here
        // so the named-argument API stays stable.
        unset($timeoutSec);
    }

    /**
     * Send a signed request and return the parsed JSON body. Object members whose value is
     * `null` are not sent; see {@see self::requestValue()} and {@see self::encodeBody()}.
     *
     * `$method` is signed and sent in upper case. `$idempotencyKey`, when not empty, is sent
     * as `Idempotency-Key` and covered by the signature.
     *
     * @param mixed $body
     * @return mixed
     */
    public function request(string $method, string $path, $body = null, string $idempotencyKey = '')
    {
        if ($method === '') {
            throw new CryptoChiefException('cryptochief: request method is required');
        }
        if (!str_starts_with($path, '/')) {
            throw new CryptoChiefException('cryptochief: request path must start with "/": ' . $path);
        }
        if ($idempotencyKey !== '' && preg_match(self::IDEMPOTENCY_KEY_RE, $idempotencyKey) !== 1) {
            throw new CryptoChiefException(
                'cryptochief: idempotency key must be printable ASCII without a leading or trailing space or tab'
            );
        }

        return $this->send(
            Sign::upperMethod($method),
            $path,
            self::encodeBody(self::requestValue($body)),
            $idempotencyKey,
        );
    }

    /**
     * JSON request body. `null` gives an empty body, a top-level `[]` gives `{}`. Integers
     * are written exactly, floats by json_encode() per the `serialize_precision` ini setting
     * (the default -1 gives the shortest digits that round-trip), `/` and non-ASCII
     * characters unescaped. Throws CryptoChiefException on invalid UTF-8, NAN/INF and
     * values JSON cannot represent.
     */
    private static function encodeBody(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value === []) {
            return '{}';
        }
        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $err) {
            throw new CryptoChiefException('cryptochief: encode request body: ' . $err->getMessage());
        }
    }

    /**
     * Request body value with every object member whose value is `null` removed, at any
     * depth. List elements are kept, `null` included. Enums become their value;
     * `JsonSerializable` and `toWire()` objects are replaced by what they return; other
     * objects become their public properties. An object left without members, or with keys
     * `0..n-1`, stays an object.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function requestValue(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \JsonSerializable) {
            return self::requestValue($value->jsonSerialize());
        }
        if (is_object($value) && method_exists($value, 'toWire')) {
            return self::requestValue($value->toWire());
        }
        if (is_object($value)) {
            return self::requestObject(get_object_vars($value));
        }
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::requestValue(...), $value);
        }

        return self::requestObject($value);
    }

    /**
     * @param array<mixed> $members
     * @return array<mixed>|\stdClass
     */
    private static function requestObject(array $members): array|\stdClass
    {
        $out = [];
        foreach ($members as $key => $member) {
            if ($member !== null) {
                $out[$key] = self::requestValue($member);
            }
        }

        return array_is_list($out) ? (object) $out : $out;
    }

    /**
     * @return mixed
     */
    private function send(string $method, string $path, string $body, string $idempotencyKey)
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        // The API signs the percent-decoded path. The request URI keeps valid escapes and
        // escapes everything else, so decoding `$path` gives the same string.
        $routePath = rawurldecode(substr($path, 0, strcspn($path, '?#')));

        $attempts = $this->retries + 1;
        $lastErr = null;
        $clockCorrected = false;
        $repeatNow = false;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($attempt > 0 && !$repeatNow) {
                $this->sleep(self::backoffDelay($attempt, $this->baseMs, $this->maxMs));
            }
            $repeatNow = false;

            $request = $this->buildRequest($method, $url, $routePath, $body, $idempotencyKey);

            try {
                $response = $this->sendRequest($request);
            } catch (\Throwable $err) {
                $lastErr = new ApiException(ErrorCode::NetworkError, message: $err->getMessage());
                if (!$lastErr->isRetryable()) {
                    throw $lastErr;
                }
                continue;
            }

            $status = $response->getStatusCode();
            $text = (string) $response->getBody();

            if ($status >= 200 && $status < 300) {
                if ($text === '') {
                    return null;
                }
                try {
                    return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $err) {
                    throw new CryptoChiefException(sprintf(
                        'cryptochief: decode %s response: %s (raw=%s)',
                        $path,
                        $err->getMessage(),
                        substr($text, 0, self::MAX_RAW_IN_ERROR)
                    ));
                }
            }

            $apiErr = self::parseApiError($status, $text);
            // Clock skew: set the offset from server_time and repeat once, without
            // backoff and outside the retry budget.
            if (
                !$clockCorrected
                && $apiErr->errorCode === ErrorCode::SignatureTimestampOutOfRange->value
                && $apiErr->serverTime !== null
            ) {
                $clockCorrected = true;
                $this->clockOffset = $apiErr->serverTime - time();
                $lastErr = $apiErr;
                $attempts++;
                $repeatNow = true;
                continue;
            }
            if ($status >= 500) {
                $lastErr = $apiErr;
                continue;
            }
            throw $apiErr;
        }

        throw $lastErr ?? new CryptoChiefException('cryptochief: retry budget exhausted');
    }

    /**
     * Request with the HMAC v1 headers. `$routePath` is the percent-decoded path without the
     * base URL; the query is taken from the request URI as sent.
     */
    private function buildRequest(
        string $method,
        string $url,
        string $routePath,
        string $body,
        string $idempotencyKey,
    ): Psr7Request {
        $headers = [
            'Accept' => 'application/json',
            Sign::HEADER_MERCHANT => $this->merchantId,
            'User-Agent' => $this->userAgent,
        ];
        if ($body !== '') {
            $headers['Content-Type'] = 'application/json';
        }
        if ($idempotencyKey !== '') {
            $headers[Sign::HEADER_IDEMPOTENCY_KEY] = $idempotencyKey;
        }
        $request = new Psr7Request($method, $url, $headers, $body);

        $timestamp = (string) (time() + $this->clockOffset);
        $nonce = Sign::hmacV1Nonce();
        $hmac = Sign::hmacV1Sign(
            $this->apiKey,
            $timestamp,
            $nonce,
            $request->getMethod(),
            $routePath,
            $request->getUri()->getQuery(),
            $request->getHeaderLine(Sign::HEADER_MERCHANT),
            $request->getHeaderLine(Sign::HEADER_IDEMPOTENCY_KEY),
            $body,
        );

        return $request
            ->withHeader(Sign::HEADER_TIMESTAMP, $timestamp)
            ->withHeader(Sign::HEADER_NONCE, $nonce)
            ->withHeader(Sign::HEADER_SIGNATURE, Sign::HMAC_V1_PREFIX . $hmac);
    }

    private function sendRequest(Psr7Request $request): ResponseInterface
    {
        if ($this->http instanceof GuzzleClient) {
            try {
                // http_errors=false: non-2xx responses come back as $response, not as
                // BadResponseException. A user-supplied Guzzle client may still have the
                // default http_errors=true, so catch and extract the response too.
                return $this->http->send($request, ['http_errors' => false]);
            } catch (BadResponseException $e) {
                return $e->getResponse();
            }
        }
        return $this->http->sendRequest($request);
    }

    /**
     * Parse a non-2xx response body into an ApiException with a stable code.
     *
     * `error` is a string (`{"ok":false,"error":...,"msg":...}`): the code is `error` unless
     * `error` is missing or `SERVICE_ERROR`, in which case it is `msg` — falling back to
     * `error` and then to `HTTP_<status>`. The message prefers `msg`, falling back to `error`.
     *
     * `error` is an object (`{"data":null,"error":{"status","name","message","details"}}`):
     * the code is `error.details.code`, falling back to `error.name` and then to `HTTP_<status>`; the message is
     * `error.message`.
     *
     * `server_time` is read from the top level, then from `error.details.server_time`.
     */
    public static function parseApiError(int $status, string $body): ApiException
    {
        $env = [];
        if ($body !== '') {
            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $env = $decoded;
                }
            } catch (\JsonException) {
                // non-JSON error body -> fall back to HTTP_<status>
            }
        }

        $serverTime = self::unixSeconds($env['server_time'] ?? null);

        if (isset($env['error']) && is_array($env['error'])) {
            $details = isset($env['error']['details']) && is_array($env['error']['details'])
                ? $env['error']['details']
                : [];
            $code = self::nonEmptyString($details['code'] ?? null)
                ?? self::nonEmptyString($env['error']['name'] ?? null)
                ?? ('HTTP_' . $status);
            $message = self::nonEmptyString($env['error']['message'] ?? null);
            $serverTime ??= self::unixSeconds($details['server_time'] ?? null);

            return new ApiException($code, $status, $message, $body, $serverTime);
        }

        $msg = self::nonEmptyString($env['msg'] ?? null);
        $err = self::nonEmptyString($env['error'] ?? null);

        $code = $err !== null && $err !== ErrorCode::ServiceError->value ? $err : $msg;
        $code ??= $err ?? ('HTTP_' . $status);

        $message = $msg ?? $err;

        return new ApiException($code, $status, $message, $body, $serverTime);
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Positive integer or decimal-digit string, otherwise null. */
    private static function unixSeconds(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^\d{1,18}$/', $value) === 1 && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Exponential backoff with full jitter, capped at $maxMs. `$attempt` is 1-indexed
     * (first retry = 1). Returns seconds.
     */
    public static function backoffDelay(int $attempt, float $baseMs, float $maxMs): float
    {
        if ($baseMs <= 0) {
            $baseMs = 200.0;
        }
        if ($maxMs <= 0) {
            $maxMs = 5000.0;
        }
        $d = $baseMs * (2 ** ($attempt - 1));
        if ($d <= 0 || $d > $maxMs) {
            $d = $maxMs;
        }
        return (mt_rand(0, (int) round($d)) / 1000.0);
    }

    /**
     * Hookable sleep so tests can run without burning seconds.
     */
    protected function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        usleep((int) round($seconds * 1_000_000));
    }
}
