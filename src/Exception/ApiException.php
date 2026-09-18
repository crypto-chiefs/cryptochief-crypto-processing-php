<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Exception;

use CryptoChief\Processing\ErrorCode;

/**
 * A typed Crypto Chief error response.
 *
 * `errorCode` is the machine-readable identifier to branch on, whichever envelope shape
 * the API used:
 *
 *   - {"ok":false,"error":"<CODE>","msg":"<sentence>"}
 *   - {"ok":false,"error":"SERVICE_ERROR","msg":"<CODE>"}
 *   - {"data":null,"error":{"status":...,"name":...,"message":"<sentence>","details":{"code":"<CODE>"}}}
 *   - an order body: {"id":...,"status":...,"error_code":"<CODE>","error":"<sentence>"} -
 *     `error_code` wins over the other shapes wherever it appears.
 *
 * All resolve to `<CODE>`, so every `ErrorCode` case is directly comparable. Without
 * `details.code` the code is `error.name`; a body without a code gives `HTTP_<status>`:
 *
 *     try {
 *         $client->payouts()->execute($req);
 *     } catch (ApiException $e) {
 *         if ($e->errorCode === ErrorCode::InsufficientFunds->value) {
 *             // top up and retry
 *         }
 *     }
 *
 * `getMessage()` carries the human-readable sentence the API sent alongside the code, and
 * `$raw` the untouched response body, `$serverTime` the `server_time` from the body (Unix
 * seconds; sent with `SIGNATURE_TIMESTAMP_OUT_OF_RANGE`), otherwise null.
 *
 * The field is named `errorCode` (not `code`) because the parent `\Exception` already
 * declares a non-readonly `$code` property of type `int`, and PHP 8.1 forbids redeclaring
 * it as a `readonly string`.
 */
class ApiException extends CryptoChiefException
{
    public readonly string $errorCode;
    public readonly int $httpStatus;
    public readonly ?string $raw;
    public readonly ?int $serverTime;

    public function __construct(
        string|ErrorCode $code,
        int $httpStatus = 0,
        ?string $message = null,
        ?string $raw = null,
        ?int $serverTime = null
    ) {
        $this->errorCode = $code instanceof ErrorCode ? $code->value : $code;
        $this->httpStatus = $httpStatus;
        $this->raw = $raw;
        $this->serverTime = $serverTime;
        parent::__construct(self::format($httpStatus, $this->errorCode, $message));
    }

    private static function format(int $status, string $code, ?string $message): string
    {
        if ($status === 0) {
            return 'cryptochief: ' . $code;
        }
        if ($message !== null && $message !== '' && $message !== $code) {
            return sprintf('cryptochief: %d %s: %s', $status, $code, $message);
        }

        return sprintf('cryptochief: %d %s', $status, $code);
    }

    /**
     * Only 5xx responses and transport `NETWORK_ERROR` failures are retryable; 4xx is
     * never retried.
     */
    public function isRetryable(): bool
    {
        return $this->httpStatus >= 500 || $this->errorCode === ErrorCode::NetworkError->value;
    }
}
