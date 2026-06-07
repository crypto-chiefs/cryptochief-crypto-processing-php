<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

class EstimatePayoutRequest extends BaseDto
{
    /**
     * @param string[]|null $fromAddresses
     */
    public function __construct(
        public readonly string $network,
        public readonly string $coin,
        public readonly string $amount,
        public readonly string $toAddress,
        public readonly ?array $fromAddresses = null,
        public readonly ?bool $allowMultipleSources = null,
        public readonly ?bool $autoConvert = null,
        public readonly ?AssetsPolicy $autoConvertPolicy = null,
        public readonly ?string $maxFeeAmountFiat = null,
        public readonly ?string $memo = null,
    ) {}
}
