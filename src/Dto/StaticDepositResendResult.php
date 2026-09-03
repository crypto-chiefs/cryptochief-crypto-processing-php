<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * The resend of a static deposit's webhook. `deliveries` has one entry — the
 * newest delivery for the deposit — kept as a list so the shape matches the
 * white-label platform, which may requeue several.
 */
final class StaticDepositResendResult extends BaseDto
{
    /**
     * @param WebhookResendResult[]|null $deliveries
     */
    public function __construct(
        public readonly string $uuid = '',
        public readonly ?array $deliveries = null,
        public readonly int $queued = 0,
        public readonly int $total = 0,
    ) {}
}
