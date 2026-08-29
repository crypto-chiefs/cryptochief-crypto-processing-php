<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Webhook;

use CryptoChief\Processing\Dto\BaseDto;

/**
 * Funds swept off a deposit wallet, confirmed on chain. Event name:
 * `sweep.confirmed` - the only sweep event the platform emits.
 *
 * There is deliberately no `sweep.broadcasted`: "we sent it" is not something
 * you can act on, and an event that means "maybe" is one more thing to
 * reconcile.
 *
 * A `static_deposit.paid` tells you a customer paid you. This tells you the
 * money has finished moving into your own custody - until it fires, the balance
 * still sits on the deposit address. Reconciliation, treasury reporting and
 * "funds available to pay out" all key off this event, not off the deposit.
 *
 * Sweeps run on static deposit wallets AND on the transit wallets issued per
 * pay-in order; both deliver here, to the callback URL configured for the
 * wallet the funds left.
 */
final class SweepEvent extends BaseDto
{
    public function __construct(
        public readonly string $event = '',
        /** The sweeper task. One sweep settles once - use it as your idempotency key. */
        public readonly string $taskId = '',
        /** Always `completed`. A sweep reaches you in no other state. */
        public readonly string $status = '',
        /** The wallet the funds left - the address your customer paid into. */
        public readonly string $walletAddress = '',
        /** The master wallet they landed on. */
        public readonly ?string $toAddress = null,
        public readonly string $network = '',
        public readonly ?string $chainFamily = null,
        public readonly string $assetSymbol = '',
        public readonly ?string $assetContract = null,
        /** `native` or `token`. */
        public readonly ?string $assetType = null,
        public readonly ?string $amountRaw = null,
        public readonly ?string $amountHuman = null,
        public readonly string $sweepTxHash = '',
        /** Set when the platform had to fund gas on the wallet before it could sweep. */
        public readonly ?string $gasPumpTxHash = null,
        /**
         * What makes this event true rather than hopeful, and never zero. It
         * travels with the event rather than being implied by it: "confirmed"
         * is not the same number on every chain, so if you run your own
         * finality policy you need the count to apply it.
         */
        public readonly int $sweepConfirmations = 0,
        /**
         * When the chain was observed to hold the sweep. NOT the task's
         * completion timestamp, which is stamped on every terminal outcome -
         * failures included - and so says nothing about settlement.
         */
        public readonly ?string $confirmedAt = null,
        /** What triggered it: `momentum`, `threshold` or `force`. */
        public readonly ?string $typeWork = null,
        /** What the sweep cost: network fee plus any gas or energy the platform fronted. */
        public readonly ?string $totalFeeUsd = null,
    ) {}
}
