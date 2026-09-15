<?php

declare(strict_types=1);

namespace CryptoChief\Processing;

/**
 * Sweep status.
 *
 * A sweep is broadcast first and confirmed after: `Broadcasted` means the transaction is
 * out and not yet final, `Completed` means the sweep is closed as confirmed. Finality depth
 * is checked by `Sweep::isFinal()`.
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

    /**
     * Whether the status is `Completed`.
     *
     * A `completed` history row with `sweepConfirmations` 0 was never observed on chain. Use
     * `Sweep::isFinal()`, which also checks `sweepConfirmations >= requiredConfirmations`.
     *
     * Never read `Sweep::$completedAt` for this - it is set at broadcast and on
     * `WaitingGas`/`Failed`/`Skipped`.
     */
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
