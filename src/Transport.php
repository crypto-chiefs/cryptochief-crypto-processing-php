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
 * Signed POST transport.
 *
 * Bodies are canonicalized + signed before sending. 5xx responses and network errors
 * retry with exponential backoff + full jitter; 4xx is never retried.
 */
final class Transport
{
    private const MAX_RAW_IN_ERROR = 512;

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
     * Send a signed POST and return the parsed JSON body.
     *
     * @param mixed $body
     * @return mixed
     */
    public function post(string $path, $body = null)
    {
        [$canonical, $signature] = Sign::signValue($body, $this->apiKey);

        $url = rtrim($this->baseUrl, '/') . $path;
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Merchant' => $this->merchantId,
            'Signature' => $signature,
            'User-Agent' => $this->userAgent,
        ];
        $request = new Psr7Request('POST', $url, $headers, $canonical);

        $attempts = $this->retries + 1;
        $lastErr = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($attempt > 0) {
                $this->sleep(self::backoffDelay($attempt, $this->baseMs, $this->maxMs));
            }

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
            if ($status >= 500) {
                $lastErr = $apiErr;
                continue;
            }
            throw $apiErr;
        }

        throw $lastErr ?? new CryptoChiefException('cryptochief: retry budget exhausted');
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
     * A refusal arrives in one of two envelope shapes. When the API decides it itself the
     * machine code is in `error` and `msg` carries an English sentence; when it relays a
     * refusal from an upstream service `error` is the generic `SERVICE_ERROR` marker and
     * the machine code is in `msg`. So the code is `error` unless `error` is missing or
     * `SERVICE_ERROR`, in which case it is `msg` — falling back to `error` and then to
     * `HTTP_<status>`. The human-readable message prefers `msg`, falling back to `error`.
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

        $msg = isset($env['msg']) && is_string($env['msg']) && $env['msg'] !== '' ? $env['msg'] : null;
        $err = isset($env['error']) && is_string($env['error']) && $env['error'] !== '' ? $env['error'] : null;

        $code = $err !== null && $err !== ErrorCode::ServiceError->value ? $err : $msg;
        $code ??= $err ?? ('HTTP_' . $status);

        $message = $msg ?? $err;

        return new ApiException($code, $status, $message, $body);
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
