<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * One outbound webhook, with every attempt the platform made and the body it
 * sent. Null means "not recorded", distinct from zero or empty.
 *
 * `status` is one of `pending` (queued, not yet attempted or waiting for a
 * retry), `in_progress` (a worker holds it right now), `delivered` (your
 * endpoint answered 2xx), `failed` (every attempt so far was refused or timed
 * out), `cancelled` (superseded by a newer event before it was ever sent).
 *
 * `reference` is the object the event was about — the order or static deposit
 * uuid you already hold. `supersededBy` names the NEWER event for the same
 * object when there is one; a superseded delivery cannot be resent — resend the
 * latest event instead.
 */
final class WebhookDelivery extends BaseDto
{
    /**
     * @param WebhookAttempt[]|null $attemptHistory
     */
    public function __construct(
        public readonly string $uuid = '',
        public readonly string $eventType = '',
        public readonly string $reference = '',
        public readonly string $targetUrl = '',
        public readonly string $status = '',
        public readonly int $attempts = 0,
        public readonly int $maxAttempts = 0,
        public readonly int $resendCount = 0,
        public readonly ?string $lastError = null,
        public readonly ?int $lastHttpStatus = null,
        public readonly ?string $nextAttemptAt = null,
        public readonly ?string $deliveredAt = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $supersededBy = null,
        public readonly ?array $attemptHistory = null,
        public readonly ?WebhookPayload $payload = null,
    ) {}
}
