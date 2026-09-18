<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * A native-coin purchase.
 *
 * Either name `network`, `receiveAddress` and `amount` (priced and bought in the same
 * call), or pass `quoteRef` to buy at a price `quote()` already held. `receiveAddress`
 * is any address - the platform pays for the transfer.
 */
final class NativeBuyRequest extends BaseDto
{
    public function __construct(
        /** Network of the coin to buy - a platform identifier (TRON_MAINNET, ...), not a coin ticker. Required unless `quoteRef` is given. */
        public readonly ?string $network = null,
        /** Address the coins are sent to. Required unless `quoteRef` is given. */
        public readonly ?string $receiveAddress = null,
        /** Amount to buy, human units as a decimal string. */
        public readonly ?string $amount = null,
        /** `ref` of a quote from `NativeService::quote()`, to buy at that held price. */
        public readonly ?string $quoteRef = null,
    ) {}
}
