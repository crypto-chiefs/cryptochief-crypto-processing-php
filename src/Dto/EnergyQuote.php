<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * A held price for a TRON energy rental.
 *
 * Amounts come in three denominations of the same figure: `priceSun` is the authoritative
 * integer in SUN, `priceTrx` the human-readable TRX decimal string, `priceUsd` / `credits`
 * the charge as it would hit the credits balance (10 000 000 credits = 1 USD). `credits`
 * is computed the same way the actual charge will be, so it agrees with a later `rent()`
 * unless the TRX rate moves in between.
 *
 * The dollar and credits figures (`priceUsd`, `credits`, `trxUsd`, `burnPriceUsd`,
 * `burnPriceCredits`, `savingUsd`, `savingCredits`) are null when no TRX rate is
 * available - a guessed rate is worse than no figure.
 *
 * The `burn*` / `saving*` fields publish what the same transfer would cost paying the
 * chain directly, so the saving is checkable rather than claimed. `recipientState` is why
 * the energy figure is what it is: an address that already holds the token needs about
 * half as much as one that does not.
 *
 * Pass `ref` as `quoteRef` to `EnergyService::rent()` to buy at this price before
 * `expiresAt`.
 */
final class EnergyQuote extends BaseDto
{
    public function __construct(
        /** Quote reference; buy at this price by passing it as `quoteRef` to rent(). */
        public readonly string $ref = '',
        /** Echo of the address the energy would be delegated to. */
        public readonly string $receiveAddress = '',
        public readonly int $energy = 0,
        public readonly int $durationSec = 0,
        /** Price in SUN - the authoritative integer. */
        public readonly int $priceSun = 0,
        /** The same price as a human-readable TRX decimal string. */
        public readonly string $priceTrx = '',
        /** The price in USD at `trxUsd`, or null when no rate is available. */
        public readonly ?string $priceUsd = null,
        /** The credits the order would charge; compare against the credits balance. */
        public readonly ?int $credits = null,
        /** The TRX/USD rate the dollar and credits figures were computed at. */
        public readonly ?string $trxUsd = null,
        /** Why the energy figure is what it is (e.g. the recipient already holds the token). */
        public readonly string $recipientState = '',
        /** What the same transfer would cost burning TRX directly, in SUN. */
        public readonly int $burnPriceSun = 0,
        public readonly string $burnPriceTrx = '',
        public readonly ?string $burnPriceUsd = null,
        public readonly ?int $burnPriceCredits = null,
        public readonly string $savingTrx = '',
        public readonly ?string $savingUsd = null,
        public readonly ?int $savingCredits = null,
        /** RFC 3339 UTC; the quote cannot be used past this moment. */
        public readonly string $expiresAt = '',
        public readonly int $expiresInSec = 0,
    ) {}
}
