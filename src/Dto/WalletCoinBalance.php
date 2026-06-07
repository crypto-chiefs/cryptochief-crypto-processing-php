<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class WalletCoinBalance extends BaseDto
{
    public function __construct(
        public readonly ?string $address = null,
        public readonly ?string $chain = null,
        public readonly ?string $coin = null,
        public readonly ?string $contract = null,
        public readonly int $decimals = 0,
        public readonly ?string $value = null,
        public readonly ?string $humanValue = null,
        public readonly ?string $amountUsd = null,
        public readonly ?int $timestamp = null,
    ) {}
}
