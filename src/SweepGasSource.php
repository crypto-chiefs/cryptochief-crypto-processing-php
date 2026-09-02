<?php

declare(strict_types=1);

namespace CryptoChief\Processing;

/**
 * What a TRON sweep buys the energy with. Carried and ignored on every other chain.
 *
 * - `Native`: the wallet burns its own TRX for the energy the transfer needs.
 * - `Rented`: the platform supplies the energy, so nothing is burnt. The energy is billed
 *   to your API credits once the transfer is on chain, whatever the `fee_mode`.
 *
 * `gasSource` answers *what is bought* where {@see SweepFeeMode} answers *who makes up a
 * shortfall in the network fees*; the two are independent.
 *
 * Not setting it is NOT the same as setting `Native`. A wallet that has never chosen one
 * gets the platform default, which is `Rented` - so energy is supplied, and billed, with
 * nobody having switched it on. To have the wallet burn its own TRX, send `Native`
 * explicitly. Read `effective->gasSource` to see which one will actually apply.
 */
enum SweepGasSource: string
{
    case Native = 'native';
    case Rented = 'rented';
}
