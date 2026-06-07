<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class PayoutFeeInfo extends BaseDto
{
    public function __construct(
        public readonly ?string $feeMode = null,
        public readonly ?string $estimatedFiat = null,
        public readonly ?string $estimatedCoin = null,
        public readonly ?string $estimatedAsset = null,
    ) {}
}
