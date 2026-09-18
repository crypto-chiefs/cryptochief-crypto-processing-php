<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * A held price for a native-coin purchase.
 *
 * The price covers the coins at the current rate plus the platform's own transfer
 * fee: `coinPriceUsd` is the coins at `coinUsd`, `transferFee` / `transferFeeUsd` the
 * fee (in the native coin and its USD worth), `subtotalUsd` the two summed and
 * `totalUsd` the full sale price. `credits` is the charge as it would hit the credits
 * balance (10 000 000 credits = 1 USD), computed the same way the actual charge will
 * be, so it agrees with a later `buy()` unless the rate moves in between.
 *
 * Pass `ref` as `quoteRef` to `NativeService::buy()` to buy at this price before
 * `expiresAt` - a quote is single-use and lives about `expiresInSec` seconds.
 */
final class NativeQuote extends BaseDto
{
    public function __construct(
        /** Quote reference; buy at this price by passing it as `quoteRef` to buy(). */
        public readonly string $ref = '',
        /** Network of the coin to buy. */
        public readonly string $network = '',
        /** Echo of the address the coins would be sent to. */
        public readonly string $receiveAddress = '',
        /** Amount to buy, human units as a decimal string. */
        public readonly string $amount = '',
        /** The coins' worth in USD at `coinUsd`. */
        public readonly string $coinPriceUsd = '',
        /** The platform's transfer fee, in the native coin. */
        public readonly string $transferFee = '',
        /** The same fee in USD at `coinUsd`. */
        public readonly string $transferFeeUsd = '',
        /** `coinPriceUsd` + `transferFeeUsd`, before the final sale price. */
        public readonly string $subtotalUsd = '',
        /** The full sale price in USD - what the order charges. */
        public readonly string $totalUsd = '',
        /** The credits the order would charge; compare against the credits balance. */
        public readonly int $credits = 0,
        /** The coin/USD rate the dollar and credits figures were computed at. */
        public readonly string $coinUsd = '',
        /** RFC 3339 UTC; the quote cannot be used past this moment. */
        public readonly string $expiresAt = '',
        public readonly int $expiresInSec = 0,
    ) {}
}
