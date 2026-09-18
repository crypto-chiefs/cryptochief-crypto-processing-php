<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * Price request for a native-coin purchase. Free of charge.
 *
 * `receiveAddress` is any address - the coins are sent to it and the platform pays for
 * the transfer. `amount` is the amount to buy in human units as a decimal string
 * (e.g. "0.05").
 */
final class NativeQuoteRequest extends BaseDto
{
    public function __construct(
        /** Network of the coin to buy - a platform identifier (TRON_MAINNET, ETH_MAINNET, ...), not a coin ticker. */
        public readonly string $network,
        /** Address the coins are sent to. */
        public readonly string $receiveAddress,
        /** Amount to buy, human units as a decimal string. */
        public readonly string $amount,
    ) {}
}
