<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class Withdrawal extends BaseDto
{
    public function __construct(
        public readonly string $uuid = '',
        public readonly string $status = '',
        public readonly ?string $network = null,
        public readonly ?string $coin = null,
        public readonly ?string $contract = null,
        public readonly ?string $amount = null,
        public readonly ?string $amountFiat = null,
        public readonly ?string $fromAddress = null,
        public readonly ?string $toAddress = null,
        public readonly ?string $txHash = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
        public readonly ?string $confirmedAt = null,
        public readonly ?string $error = null,
    ) {}
}
