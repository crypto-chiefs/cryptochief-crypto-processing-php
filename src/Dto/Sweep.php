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
         * Confirmations seen on the sweep transaction, and when it reached the network's
         * confirmation target. Read them with `status`: `completedAt` is absent while the
         * sweep is still in flight.
         */
        public readonly ?int $sweepConfirmations = null,
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
