<?php

declare(strict_types=1);

namespace CryptoChief\Processing;

/**
 * Sweep status.
 *
 * A sweep is broadcast first and confirmed after: `Broadcasted` means the transaction is
 * out and not yet confirmed, `Completed` means the chain confirmed it. The platform used
 * to report `completed` at broadcast, so a sweep could read as settled while its
 * transaction was still unconfirmed or had been dropped.
 *
 * `Skipped` is a sweep the platform decided against - almost always a balance below the
 * wallet's threshold. A normal outcome, not a failure.
 */
enum SweepStatus: string
{
    case Pending     = 'pending';
    case WaitingGas  = 'waiting_gas';
    case Broadcasted = 'broadcasted';
    case Completed   = 'completed';
    case Failed      = 'failed';
    case Skipped     = 'skipped';

    /** Whether the chain has confirmed this sweep. */
    public function isSettled(): bool
    {
        return $this === self::Completed;
    }

    /** Whether the platform is still working on it. */
    public function isInFlight(): bool
    {
        return match ($this) {
            self::Pending, self::WaitingGas, self::Broadcasted => true,
            default => false,
        };
    }
}
