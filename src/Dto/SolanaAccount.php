<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class SolanaAccount extends BaseDto
{
    public function __construct(
        public readonly string $pubkey,
        public readonly bool $isSigner,
        public readonly bool $isWritable,
    ) {}
}
