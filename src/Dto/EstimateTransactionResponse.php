<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * Network-fee estimate for a transaction that has NOT been signed or broadcast.
 *
 * `estimatedFee` is the network fee in the native coin, `required` the total native
 * balance the `fromAddress` wallet must hold (fee + value for `native`, fee only for
 * `token`). Both are human-readable decimal strings. `estimatedFeeFiat` and
 * `requiredFiat` are the same amounts in USD, or an empty string when no rate is
 * available.
 *
 * TRON estimates additionally carry a fee breakdown; every other family leaves these
 * null (the fields are absent from the JSON):
 *
 *   - `feeExpected` - what the transfer will probably cost given the energy the
 *     `fromAddress` wallet currently holds (staked / delegated / rented). NOT a
 *     guarantee: the pool can expire or be spent by another transfer between this
 *     estimate and the broadcast, so fund `estimatedFee`, not this.
 *   - `feeLimit` - the on-chain cap written into the transaction.
 *   - `energy` - energy units the transaction needs.
 *   - `energyFee`, `bandwidthFee`, `activationFee` - the gross burn with an empty
 *     pool; the three sum to `estimatedFee`. `activationFee` is set only for a
 *     native transfer to an address the chain has not seen yet.
 */
final class EstimateTransactionResponse extends BaseDto
{
    public function __construct(
        public readonly string $network = '',
        public readonly string $chainFamily = '',
        public readonly string $type = '',
        public readonly string $fromAddress = '',
        public readonly string $toAddress = '',
        public readonly string $estimatedFee = '',
        public readonly string $estimatedFeeFiat = '',
        public readonly string $required = '',
        public readonly string $requiredFiat = '',
        /** TRON only: expected fee given the wallet's current energy pool; NOT a guarantee. */
        public readonly ?string $feeExpected = null,
        /** TRON only: the on-chain fee cap written into the transaction. */
        public readonly ?string $feeLimit = null,
        /** TRON only: energy units the transaction needs. */
        public readonly ?int $energy = null,
        /** TRON only: TRX burned for energy with an empty pool. */
        public readonly ?string $energyFee = null,
        /** TRON only: TRX burned for bandwidth. */
        public readonly ?string $bandwidthFee = null,
        /** TRON only: TRX for activating a previously unseen address (native transfers). */
        public readonly ?string $activationFee = null,
    ) {}
}
