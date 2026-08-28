<?php

declare(strict_types=1);

namespace CryptoChief\Processing;

/**
 * Who pays the gas for a sweep.
 *
 * - `Client`: taken from the swept wallet itself.
 * - `Service`: paid by the platform's service wallet.
 * - `Mix`: the service wallet funds the gas and the cost is reclaimed from the sweep.
 */
enum SweepFeeMode: string
{
    case Client  = 'client';
    case Service = 'service';
    case Mix     = 'mix';
}
