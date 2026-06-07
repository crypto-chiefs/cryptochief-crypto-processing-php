<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * ERC-20 / TRC-20 transfer one-liner. `amount` is in token base units - use Amount::humanToBase
 * with the token's decimals to produce it.
 */
final class Erc20TransferRequest
{
    public function __construct(
        public readonly string $network,
        public readonly string $fromAddress,
        public readonly string $tokenContract,
        public readonly string $recipient,
        public readonly string $amount,
        public readonly ?string $urlCallback = null,
    ) {}
}
