<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * Price request for a TRON energy rental. Free of charge.
 *
 * `receiveAddress` is the SENDER of the transfer the energy is for - the address the
 * energy is delegated to. `energy` and `durationSec` are optional; omitted, the service
 * prices its default amount and duration (currently enough for one USDT transfer to an
 * address that holds no USDT, for one hour).
 */
final class EnergyQuoteRequest extends BaseDto
{
    public function __construct(
        public readonly string $receiveAddress,
        /** Energy units to rent. */
        public readonly ?int $energy = null,
        /** How long the delegation lasts, seconds. */
        public readonly ?int $durationSec = null,
    ) {}
}
