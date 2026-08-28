<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * A resolved set of sweep rules.
 */
final class SweepPolicy extends BaseDto
{
    public function __construct(
        public readonly string $typeWork = '',
        /** Meaningful only when `typeWork` is `threshold`. */
        public readonly ?string $thresholdAmountUsd = null,
        public readonly string $feeMode = '',
        /**
         * Which layer the mode came from: `wallet_network`, `wallet`, `project` or
         * `default`. Present on the effective policy, where the question arises.
         */
        public readonly ?string $source = null,
    ) {}
}
