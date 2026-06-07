<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class TransactionInfo extends BaseDto
{
    public function __construct(
        public readonly string $uuid = '',
        public readonly string $status = '',
        public readonly ?string $network = null,
        public readonly ?string $chainFamily = null,
        public readonly ?string $fromAddress = null,
        public readonly ?string $toAddress = null,
        public readonly ?string $type = null,
        public readonly ?string $value = null,
        public readonly ?string $coin = null,
        public readonly ?string $contract = null,
        public readonly ?string $txHash = null,
        public readonly ?string $signedTxHex = null,
        public readonly ?string $expiresAt = null,
        public readonly ?int $nonce = null,
        public readonly ?string $actualFee = null,
        public readonly ?string $actualFeeFiat = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
        public readonly ?string $error = null,
    ) {}

    public function isTerminal(): bool
    {
        return in_array($this->status, ['confirmed', 'failed', 'expired'], true);
    }
}
