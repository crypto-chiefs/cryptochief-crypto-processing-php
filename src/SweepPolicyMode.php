<?php

declare(strict_types=1);

namespace CryptoChief\Processing;

/**
 * Auto-sweep modes.
 *
 * - `Off`: never swept on its own. A force sweep still works.
 * - `Momentum`: swept as soon as funds arrive.
 * - `Threshold`: swept once the balance reaches `thresholdAmountUsd`. A held balance is
 *   re-checked periodically, so a wallet that crosses the threshold through price
 *   movement alone is still swept.
 */
enum SweepPolicyMode: string
{
    case Off       = 'turned_off';
    case Momentum  = 'momentum';
    case Threshold = 'threshold';
}
