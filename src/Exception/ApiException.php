<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Exception;

use CryptoChief\Processing\ErrorCode;

/**
 * A typed Crypto Chief error response.
 *
 * The API returns either {"error":"SERVICE_ERROR","msg":"<CODE>",...} (then `errorCode`
 * is `<CODE>`) or {"error":"<CODE>",...} (then `errorCode` is that value). Either way
 * `errorCode` is the stable identifier to branch on:
 *
 *     try {
 *         $client->payouts()->execute($req);
 *     } catch (ApiException $e) {
 *         if ($e->errorCode === ErrorCode::InsufficientFunds->value) {
 *             // top up and retry
 *         }
 *     }
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

    public function __construct(
        string|ErrorCode $code,
        int $httpStatus = 0,
        ?string $message = null,
        ?string $raw = null
    ) {
        $this->errorCode = $code instanceof ErrorCode ? $code->value : $code;
        $this->httpStatus = $httpStatus;
        $this->raw = $raw;
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
