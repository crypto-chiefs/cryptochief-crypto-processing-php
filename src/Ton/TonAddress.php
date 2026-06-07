<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Ton;

/**
 * Parsed TON address. The 32-byte hash + 1-byte workchain plus a couple of UI flags
 * captured from the bounceable / non-bounceable / testnet tag.
 */
final class TonAddress
{
    public function __construct(
        public readonly int $workchain,
        public readonly string $hash,
        public readonly bool $bounceable,
        public readonly bool $testnet,
    ) {}
}
