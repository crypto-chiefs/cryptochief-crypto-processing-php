<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class Sweep extends BaseDto
{
    public function __construct(
        public readonly string $taskId = '',
        /** One of {@see \CryptoChief\Processing\SweepStatus}. */
        public readonly string $status = '',
        public readonly ?string $sweepTxHash = null,
        public readonly ?string $gasPumpTxHash = null,
        public readonly ?string $walletAddress = null,
        public readonly ?string $chain = null,
        public readonly ?string $chainFamily = null,
        public readonly ?string $assetSymbol = null,
        public readonly ?string $assetType = null,
        public readonly ?string $amountHuman = null,
        /** What triggered this sweep: momentum, threshold or force. */
        public readonly ?string $typeWork = null,
        /**
         * Confirmations seen on the sweep transaction. `0` until it is mined, then grows
         * while the sweep is `broadcasted`. Settled: `status` is `completed` and this count
         * is at or above `requiredConfirmations` ({@see Sweep::isFinal()}).
         */
        public readonly ?int $sweepConfirmations = null,
        /**
         * When the sweep was broadcast; also set on `waiting_gas`, `failed` and `skipped`.
         * Not settlement.
         *
         * To tell settlement apart, use {@see Sweep::isFinal()}, or take `confirmedAt`
         * from the `sweep.confirmed` webhook.
         */
        public readonly ?string $completedAt = null,
        /**
         * Fees. `totalFeeUsd` is the whole cost of the sweep; the gas-pump half is the
         * funding transfer that pays for it on chains needing one. The `real*` figures are
         * what the chain actually charged, filled in once the transaction settles; the
         * others are the estimate made up front.
         */
        public readonly ?string $totalFeeUsd = null,
        public readonly ?string $gasPumpSource = null,
        public readonly ?string $gasPumpFeeHuman = null,
        public readonly ?string $gasPumpFeeUsd = null,
        public readonly ?string $sweepFeeHuman = null,
        public readonly ?string $sweepFeeUsd = null,
        public readonly ?string $realGasPumpFeeHuman = null,
        public readonly ?string $realGasPumpFeeUsd = null,
        public readonly ?string $realSweepFeeHuman = null,
        public readonly ?string $realSweepFeeUsd = null,
        public readonly ?string $createdAt = null,
        /**
         * @deprecated never populated. The API reports fees under the names above; these
         * were guesses at a shape it does not send.
         */
        public readonly ?string $gasFeeHuman = null,
        /** @deprecated never populated. */
        public readonly ?string $gasFeeFiat = null,
        /** @deprecated never populated. */
        public readonly ?string $serviceFeeFiat = null,
        /** @deprecated never populated - sweeps carry `createdAt` and `completedAt`. */
        public readonly ?string $updatedAt = null,
        /**
         * Finality depth of the network. Settled: `status` is `completed` and
         * `sweepConfirmations >= requiredConfirmations`. A `completed` row with
         * `sweepConfirmations` 0 was never observed on chain; `isFinal()` is false for it.
         * `null` when the server does not send the field.
         */
        public readonly ?int $requiredConfirmations = null,
    ) {}

    /**
     * Settled: `status` is `completed` and `sweepConfirmations` is at or above
     * `requiredConfirmations`. Without `requiredConfirmations`: `completed` and
     * `sweepConfirmations` above zero. `false` when `sweepConfirmations` is absent.
     */
    public function isFinal(): bool
    {
        if ($this->status !== \CryptoChief\Processing\SweepStatus::Completed->value) {
            return false;
        }
        if ($this->sweepConfirmations === null) {
            return false;
        }
        if ($this->requiredConfirmations === null) {
            return $this->sweepConfirmations > 0;
        }

        return $this->sweepConfirmations >= max($this->requiredConfirmations, 1);
    }
}
