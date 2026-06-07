<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * TON contract call from a pre-built BoC body cell. `bodyCell` is raw bytes; the SDK
 * base64-encodes it for the wire.
 */
final class TonCallRequest
{
    public function __construct(
        public readonly string $network,
        public readonly string $fromAddress,
        public readonly string $contract,
        public readonly string $bodyCell,
        public readonly string|int|null $value = null,
        public readonly ?bool $bounce = null,
        public readonly ?string $urlCallback = null,
    ) {}
}
