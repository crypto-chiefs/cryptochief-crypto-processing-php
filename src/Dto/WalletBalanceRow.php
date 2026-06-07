<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class WalletBalanceRow extends BaseDto
{
    public function __construct(
        public readonly string $address = '',
        public readonly ?string $value = null,
        public readonly ?string $humanValue = null,
        public readonly int $decimals = 0,
        public readonly ?string $contract = null,
    ) {}
}
