<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class Sweep extends BaseDto
{
    public function __construct(
        public readonly string $taskId = '',
        public readonly string $status = '',
        public readonly ?string $sweepTxHash = null,
        public readonly ?string $walletAddress = null,
        public readonly ?string $chain = null,
        public readonly ?string $chainFamily = null,
        public readonly ?string $assetSymbol = null,
        public readonly ?string $assetType = null,
        public readonly ?string $amountHuman = null,
        public readonly ?string $gasFeeHuman = null,
        public readonly ?string $gasFeeFiat = null,
        public readonly ?string $serviceFeeFiat = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
    ) {}
}
