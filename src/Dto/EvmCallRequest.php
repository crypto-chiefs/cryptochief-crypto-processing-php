<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * EVM / TRON contract call by Solidity-style signature.
 */
final class EvmCallRequest
{
    /**
     * @param array<int, mixed> $args
     */
    public function __construct(
        public readonly string $network,
        public readonly string $fromAddress,
        public readonly string $contract,
        public readonly string $method,
        public readonly array $args = [],
        public readonly ?string $value = null,
        public readonly ?string $urlCallback = null,
    ) {}
}
