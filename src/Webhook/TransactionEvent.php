<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Webhook;

use CryptoChief\Processing\Dto\BaseDto;

/**
 * Transaction webhook. Fires only on terminal status (confirmed / failed / expired).
 */
final class TransactionEvent extends BaseDto
{
    public function __construct(
        public readonly string $event = '',
        public readonly string $uuid = '',
        public readonly string $status = '',
        public readonly ?string $network = null,
        public readonly ?string $chainFamily = null,
        public readonly ?string $type = null,
        public readonly ?string $fromAddress = null,
        public readonly ?string $toAddress = null,
        public readonly ?string $value = null,
        public readonly ?string $contract = null,
        public readonly ?string $txHash = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $completedAt = null,
        public readonly ?string $errorReason = null,
    ) {}
}
