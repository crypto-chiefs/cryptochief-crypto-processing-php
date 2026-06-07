<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * `orderId` is the idempotency key - resubmitting returns the same `uuid`.
 */
final class ExecutePayoutRequest extends EstimatePayoutRequest
{
    /**
     * @param string[]|null $fromAddresses
     */
    public function __construct(
        string $network,
        string $coin,
        string $amount,
        string $toAddress,
        public readonly string $orderId = '',
        public readonly string $userId = '',
        public readonly string $urlCallback = '',
        ?array $fromAddresses = null,
        ?bool $allowMultipleSources = null,
        ?bool $autoConvert = null,
        ?AssetsPolicy $autoConvertPolicy = null,
        ?string $maxFeeAmountFiat = null,
        ?string $memo = null,
    ) {
        parent::__construct(
            network: $network,
            coin: $coin,
            amount: $amount,
            toAddress: $toAddress,
            fromAddresses: $fromAddresses,
            allowMultipleSources: $allowMultipleSources,
            autoConvert: $autoConvert,
            autoConvertPolicy: $autoConvertPolicy,
            maxFeeAmountFiat: $maxFeeAmountFiat,
            memo: $memo,
        );
    }
}
