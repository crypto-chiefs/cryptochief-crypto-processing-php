<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * One chain the platform's blockchain scanner is currently connected to.
 *
 * Infrastructure-level, not your project's catalogue: it says which chains the platform
 * can read blocks from, not what you may be paid in. For that use
 * {@see \CryptoChief\Processing\Service\BlockchainService::contractsAvailable()}.
 */
final class SupportedBlockchain extends BaseDto
{
    public function __construct(
        /** The chain key - one of {@see \CryptoChief\Processing\Chain}. */
        public readonly string $name = '',
        /**
         * The protocol family the scanner reads it with: `evm`, `tron`, `solana`, ...
         * Lower-case, unlike the upper-case `chain_family` of
         * {@see \CryptoChief\Processing\ChainFamily}, so the two do not compare directly.
         */
        public readonly string $type = '',
    ) {}
}
