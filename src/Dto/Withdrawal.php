<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * A manual withdrawal, as returned by `/v1/withdrawal/info` and in `/v1/withdrawal/history`.
 */
final class Withdrawal extends BaseDto
{
    public function __construct(
        public readonly string $uuid = '',
        /**
         * One of:
         *
         * - `queue` - waiting to be processed.
         * - `refueling` - gas top-up of the source wallet in progress.
         * - `refuel_confirmed` - top-up confirmed or not needed.
         * - `broadcasting` - waiting to reach the network. EVM only.
         * - `sending` - transaction being sent.
         * - `in_mempool` - in the mempool. BTC family only.
         * - `confirm_check` - sent; waiting for `requiredConfirmations`.
         * - `completed` - final: reached `requiredConfirmations`.
         * - `failed` - final; `errorReason` says why.
         */
        public readonly string $status = '',
        public readonly ?string $network = null,
        public readonly ?string $coin = null,
        /** @deprecated never populated - the withdrawal response has no `contract`. */
        public readonly ?string $contract = null,
        /** Amount in coin units, as a decimal string. */
        public readonly ?string $amount = null,
        /** @deprecated never populated - the fee in USD is `estimatedFeeFiat` / `actualFeeFiat`. */
        public readonly ?string $amountFiat = null,
        public readonly ?string $fromAddress = null,
        public readonly ?string $toAddress = null,
        /** Hash of the withdrawal transaction. `null` until it has been sent. */
        public readonly ?string $txHash = null,
        public readonly ?string $createdAt = null,
        /** @deprecated never populated - the withdrawal response has no `updated_at`. */
        public readonly ?string $updatedAt = null,
        /** @deprecated never populated - the settlement moment is `completedAt`. */
        public readonly ?string $confirmedAt = null,
        /** @deprecated never populated - the failure reason is `errorReason`. */
        public readonly ?string $error = null,
        /** Confirmations of the withdrawal transaction. Absent until it is in a block. */
        public readonly ?int $confirmations = null,
        /**
         * Finality depth of the network. Always sent. The withdrawal is `confirm_check`
         * until `confirmations` reaches it, then `completed`.
         */
        public readonly ?int $requiredConfirmations = null,
        /** Whether the source wallet needed a gas top-up before the withdrawal could be sent. */
        public readonly ?bool $needRefuel = null,
        /** Hash of the gas top-up transaction. `null` when there was none. */
        public readonly ?string $refuelTxHash = null,
        /** Status of the gas top-up. `null` when there was none. */
        public readonly ?string $refuelStatus = null,
        /** Why the withdrawal is `failed` (e.g. `TX_CONFIRM_TIMEOUT`, `TX_REVERTED`). `null` otherwise. */
        public readonly ?string $errorReason = null,
        /** Fee in USD estimated when the withdrawal was created. */
        public readonly ?string $estimatedFeeFiat = null,
        /** Fee actually paid, in USD. Absent until `completed`. */
        public readonly ?string $actualFeeFiat = null,
        /** Who pays the network fee: `client`, `service` or `mix`. */
        public readonly ?string $feeMode = null,
        /** When the withdrawal turned `completed`. Absent on other statuses. */
        public readonly ?string $completedAt = null,
    ) {}
}
