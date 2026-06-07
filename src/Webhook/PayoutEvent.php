<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Webhook;

use CryptoChief\Processing\Dto\BaseDto;

/**
 * Payout webhook. Fires only on terminal status: `payout.paid` / `payout.system_fail`.
 *
 * @phpstan-type FeeInfo array<string, mixed>
 */
final class PayoutEvent extends BaseDto
{
    /**
     * @param array<string, mixed>|null $feeInfo
     * @param mixed $sources
     * @param mixed $serviceOperations
     */
    public function __construct(
        public readonly string $event = '',
        public readonly string $uuid = '',
        public readonly string $status = '',
        public readonly ?string $orderId = null,
        public readonly ?string $userId = null,
        public readonly ?string $amountRequested = null,
        public readonly ?string $amountToReceive = null,
        public readonly ?string $toAddress = null,
        public readonly ?array $feeInfo = null,
        public readonly mixed $sources = null,
        public readonly mixed $serviceOperations = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $completedAt = null,
        public readonly ?string $errorReason = null,
    ) {}
}
