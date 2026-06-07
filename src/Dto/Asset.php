<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * A specific coin on a specific network.
 *
 * `network` takes a chain code (e.g. Chain::EthMainnet->value) or the wildcard "ANY";
 * `coin` is the symbol (e.g. "USDT"). Either field may be omitted to mean "any".
 */
final class Asset extends BaseDto
{
    public function __construct(
        public readonly ?string $network = null,
        public readonly ?string $coin = null,
    ) {}
}
