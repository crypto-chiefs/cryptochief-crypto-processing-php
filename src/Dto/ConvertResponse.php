<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class ConvertResponse extends BaseDto
{
    public function __construct(
        public readonly float $amountCrypto = 0.0,
        public readonly float $amountFiat = 0.0,
        public readonly ?string $crypto = null,
        public readonly float $cryptoToUsdt = 0.0,
        public readonly ?string $exchange = null,
        public readonly ?string $fiat = null,
        public readonly float $fiatToUsd = 0.0,
        public readonly int $timestampCrypto = 0,
        public readonly int $timestampFiat = 0,
    ) {}
}
