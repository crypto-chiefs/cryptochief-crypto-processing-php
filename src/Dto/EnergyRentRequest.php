<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * A TRON energy rental order.
 *
 * `receiveAddress` is the SENDER of the transfer the energy is for - the address the
 * energy is delegated to. Either name it together with `energy` / `durationSec` (priced
 * and bought in the same call), or pass `quoteRef` to buy at a price `quote()` already
 * held.
 */
final class EnergyRentRequest extends BaseDto
{
    public function __construct(
        /** Address the energy is delegated to. Required unless `quoteRef` is given. */
        public readonly ?string $receiveAddress = null,
        public readonly ?int $energy = null,
        public readonly ?int $durationSec = null,
        /** `ref` of a quote from `EnergyService::quote()`, to buy at that held price. */
        public readonly ?string $quoteRef = null,
    ) {}
}
