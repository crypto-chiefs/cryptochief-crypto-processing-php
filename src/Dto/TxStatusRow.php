<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class TxStatusRow extends BaseDto
{
    public function __construct(
        public readonly int $confirmations = 0,
        public readonly ?string $fee = null,
        public readonly ?string $humanFee = null,
        public readonly ?int $blockNumber = null,
        public readonly ?string $status = null,
    ) {}
}
