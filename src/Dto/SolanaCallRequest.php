<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * Non-Anchor Solana program call with raw instruction bytes.
 */
final class SolanaCallRequest
{
    /**
     * @param SolanaAccount[] $accounts
     */
    public function __construct(
        public readonly string $network,
        public readonly string $fromAddress,
        public readonly string $program,
        public readonly string $instructionData,
        public readonly array $accounts = [],
        public readonly ?string $urlCallback = null,
    ) {}
}
