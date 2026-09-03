<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * One POST the platform made to your endpoint. Newest first in
 * {@see WebhookDelivery::$attemptHistory}.
 *
 * `httpStatus` is null when nothing answered (DNS, connect, TLS, timeout) —
 * `error` then holds the transport error. `createdAt` is null for attempts
 * recorded before the platform kept the time. `responseBody` is what your
 * endpoint answered, as the platform saw it, capped; `responseTruncated` says
 * whether it was cut.
 */
final class WebhookAttempt extends BaseDto
{
    public function __construct(
        public readonly int $attempt = 0,
        public readonly ?int $httpStatus = null,
        public readonly ?string $error = null,
        public readonly ?int $durationMs = null,
        public readonly ?string $targetUrl = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $responseBody = null,
        public readonly ?string $responseContentType = null,
        public readonly bool $responseTruncated = false,
    ) {}
}
