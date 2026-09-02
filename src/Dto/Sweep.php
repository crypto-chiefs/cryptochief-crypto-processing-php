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
         * Confirmations seen on the sweep transaction. `0` until it is mined, and above
         * zero once the chain holds the funds - this is the settlement signal.
         */
        public readonly ?int $sweepConfirmations = null,
        /**
         * When the sweep reached a TERMINAL OUTCOME - failures and skips included. The
         * sweeper stamps it at every ending, not only a successful one, so its presence
         * says the task finished and NOT that money moved: a `failed` sweep carries a
         * `completedAt` exactly like a settled one does.
         *
         * To tell settlement apart, check `sweepConfirmations` is above zero (with
         * `status` at {@see \CryptoChief\Processing\SweepStatus::Completed}), or take
         * `confirmedAt` from the `sweep.confirmed` webhook - which exists as a separate
         * field for this reason.
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
    ) {}
}
