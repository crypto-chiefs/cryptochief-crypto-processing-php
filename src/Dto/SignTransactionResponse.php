<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class SignTransactionResponse extends BaseDto
{
    public function __construct(
        public readonly string $uuid = '',
        public readonly string $status = '',
        public readonly ?string $signedTxHex = null,
        public readonly ?string $txHash = null,
        public readonly ?string $expiresAt = null,
        public readonly ?string $chainFamily = null,
        public readonly ?string $network = null,
    ) {}
}
