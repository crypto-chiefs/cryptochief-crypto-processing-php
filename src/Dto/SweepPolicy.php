<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * A resolved set of sweep rules.
 *
 * `gasSource` is new in 0.7.0 and sits last so that a positional call written against
 * 0.6.0 keeps its meaning. Prefer named arguments.
 */
final class SweepPolicy extends BaseDto
{
    public function __construct(
        public readonly string $typeWork = '',
        /** Meaningful only when `typeWork` is `threshold`. */
        public readonly ?string $thresholdAmountUsd = null,
        /**
         * Who covers a gas shortfall - one of {@see \CryptoChief\Processing\SweepFeeMode}.
         * A deposit wallet holding enough of the chain's native coin pays for its own
         * transfer whatever this says; the mode only decides who tops it up.
         */
        public readonly string $feeMode = '',
        /**
         * Which layer the mode came from: `wallet_network`, `wallet`, `project` or
         * `default`. Present on the effective policy, where the question arises.
         */
        public readonly ?string $source = null,
        /**
         * What buys the energy a TRON transfer needs - one of
         * {@see \CryptoChief\Processing\SweepGasSource}. On the effective policy this is
         * always a concrete value and is the one worth reading: a wallet that never chose
         * one is `rented`, so the platform supplies the energy and bills it to your API
         * credits. Carried and ignored on every chain other than TRON.
         */
        public readonly ?string $gasSource = null,
    ) {}
}
