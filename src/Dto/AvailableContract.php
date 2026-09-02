<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * One coin or token, as both asset endpoints describe it: the project's own catalogue
 * ({@see \CryptoChief\Processing\Service\BlockchainService::contractsAvailable()}) and
 * the platform-wide one ({@see \CryptoChief\Processing\Service\BlockchainService::contractsList()}).
 *
 * `chainFamily` and `isTest` are new in 0.7.0 and sit *after* `decimals` so that a
 * positional call written against 0.6.0 keeps its meaning. Prefer named arguments.
 */
final class AvailableContract extends BaseDto
{
    public function __construct(
        public readonly ?string $network = null,
        public readonly ?string $coin = null,
        /** The token contract. An empty string on a native coin, never null. */
        public readonly ?string $contract = null,
        /** `native` or `token`. */
        public readonly ?string $type = null,
        public readonly int $decimals = 0,
        /** The protocol family - one of {@see \CryptoChief\Processing\ChainFamily}. */
        public readonly ?string $chainFamily = null,
        /** True when the asset lives on a test network. */
        public readonly ?bool $isTest = null,
    ) {}
}
