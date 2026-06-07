<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class GenerateWalletRequest extends BaseDto
{
    public function __construct(
        public readonly string $walletType,
        public readonly string $chainFamily,
        public readonly ?string $masterWalletAddress = null,
        public readonly ?string $callbackUrl = null,
    ) {}
}
