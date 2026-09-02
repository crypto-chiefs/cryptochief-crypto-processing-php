<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Exception;

use CryptoChief\Processing\ErrorCode;

/**
 * A typed Crypto Chief error response.
 *
 * `errorCode` is the machine-readable identifier to branch on, whichever envelope shape
 * the API used: {"error":"<CODE>","msg":"<sentence>"} for a refusal the API decided
 * itself, or {"error":"SERVICE_ERROR","msg":"<CODE>"} for one relayed from an upstream
 * service. Both resolve to `<CODE>`, so every `ErrorCode` case is directly comparable:
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
 * `$raw` the untouched response body.
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
