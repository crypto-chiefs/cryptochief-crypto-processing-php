<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Webhook;

use CryptoChief\Processing\Dto\BaseDto;

/**
 * Static-deposit webhook. Event names carry the `static_deposit.` prefix.
 */
final class StaticDepositEvent extends BaseDto
{
    public function __construct(
        public readonly string $event = '',
        public readonly string $uuid = '',
        public readonly string $status = '',
        public readonly ?string $network = null,
        public readonly ?string $chainFamily = null,
        public readonly ?string $coin = null,
        public readonly ?string $contract = null,
        public readonly ?int $decimals = null,
        public readonly ?string $toAddress = null,
        public readonly ?string $fromAddress = null,
        public readonly ?string $txHash = null,
        public readonly ?string $amount = null,
        public readonly ?string $amountFiat = null,
        public readonly ?int $confirmations = null,
        public readonly ?int $requiredConfirmations = null,
        public readonly ?bool $foundInMempool = null,
        public readonly ?string $logType = null,
        public readonly ?int $blockNumber = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
        public readonly ?string $confirmedAt = null,
        public readonly ?string $paidAt = null,
    ) {}
}
