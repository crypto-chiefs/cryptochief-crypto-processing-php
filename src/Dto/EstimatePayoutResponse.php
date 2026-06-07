<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class EstimatePayoutResponse extends BaseDto
{
    /**
     * @param PayoutSource[]|null $sources
     * @param array<int, array<string, mixed>>|null $serviceOperations
     */
    public function __construct(
        public readonly ?string $network = null,
        public readonly ?string $coin = null,
        public readonly ?string $amount = null,
        public readonly ?string $amountToReceive = null,
        public readonly ?string $toAddress = null,
        public readonly ?PayoutFeeInfo $feeInfo = null,
        public readonly ?array $sources = null,
        public readonly ?array $serviceOperations = null,
        public readonly ?bool $autoConvertApplied = null,
    ) {}
}
