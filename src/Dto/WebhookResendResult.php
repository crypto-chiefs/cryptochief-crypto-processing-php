<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * What a resend did. On this platform a resend is synchronous: the POST to your
 * endpoint happens before the answer comes back, so `queued === true` arrives
 * with `status` already `delivered` or `failed` for that attempt.
 *
 * `reason` is set when `queued` is false: one of the `DELIVERY_*` /
 * `RESEND_TOO_SOON` codes in {@see \CryptoChief\Processing\ErrorCode}.
 */
final class WebhookResendResult extends BaseDto
{
    public function __construct(
        public readonly string $uuid = '',
        public readonly string $eventType = '',
        public readonly string $reference = '',
        public readonly string $status = '',
        public readonly bool $queued = false,
        public readonly int $attempts = 0,
        public readonly int $resendCount = 0,
        public readonly ?string $reason = null,
        public readonly ?string $supersededBy = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {}
}
