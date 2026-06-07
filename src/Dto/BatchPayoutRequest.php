<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * Batch body for `/payout/batch/{estimate,execute}`. Up to 50 items per call.
 */
final class BatchPayoutRequest extends BaseDto
{
    /**
     * @param ExecutePayoutRequest[] $items
     */
    public function __construct(
        public readonly array $items,
        public readonly ?string $urlCallback = null,
    ) {}
}
