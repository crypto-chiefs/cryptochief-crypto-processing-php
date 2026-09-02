<?php

declare(strict_types=1);

namespace CryptoChief\Processing;

/**
 * Who covers a gas shortfall on a sweep.
 *
 * None of these decide who pays outright. A deposit wallet that already holds enough of
 * the chain's native coin pays for its own transfer, whatever the mode - the mode only
 * decides where the missing gas comes from when it does not.
 *
 * - `Client`: your own master wallet tops the deposit wallet up.
 * - `Service`: the platform supplies it, and the cost is BILLED TO YOUR API CREDITS.
 * - `Mix`: the default. Tries `Client` first and falls back to `Service` when the master
 *   wallet cannot cover it.
 *
 * `Mix` is what a wallet that has never chosen a mode is on.
 */
enum SweepFeeMode: string
{
    case Client  = 'client';
    case Service = 'service';
    case Mix     = 'mix';
}
