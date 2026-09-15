<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class PayoutSource extends BaseDto
{
    public function __construct(
        public readonly ?string $address = null,
        /** @deprecated never populated. The amount is `amountCrypto`. */
        public readonly ?string $amount = null,
        public readonly ?string $coin = null,
        /** Confirmations of this source's transaction. Absent until it is on chain. */
        public readonly ?int $confirmations = null,
        /** Amount sent from this source, in coin units. */
        public readonly ?string $amountCrypto = null,
        /** Hash of this source's transaction. Absent until it is sent. */
        public readonly ?string $txid = null,
        public readonly ?string $network = null,
        /** Network fee actually paid. Absent until it is known. */
        public readonly ?string $feePaid = null,
        public readonly ?string $feePaidFiat = null,
        /** Whether the source needed a gas top-up before sending. */
        public readonly ?bool $needRefuel = null,
        public readonly ?string $refuelAmount = null,
        public readonly ?string $estimatedFee = null,
        public readonly ?string $estimatedFeeFiat = null,
    ) {}
}
