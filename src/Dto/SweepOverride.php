<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * What one wallet decides for itself.
 *
 * A field of `null` is not overridden - it is inherited, which no ordinary value can
 * express.
 */
final class SweepOverride extends BaseDto
{
    public function __construct(
        /**
         * Empty covers the address on every network it exists on; set, it covers that one
         * network and takes precedence over the address-wide override.
         */
        public readonly ?string $networkCode = null,
        public readonly ?string $typeWork = null,
        public readonly ?string $thresholdAmountUsd = null,
        public readonly ?string $feeMode = null,
        /** Who wrote it: `merchant` or `operator`. */
        public readonly ?string $source = null,
        /**
         * An operator pinned this policy. While it is set, a merchant write answers
         * `SWEEP_SETTINGS_LOCKED` and changes nothing.
         */
        public readonly bool $locked = false,
    ) {}
}
