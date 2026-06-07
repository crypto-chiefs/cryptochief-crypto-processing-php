<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class CoinOption extends BaseDto
{
    public function __construct(
        public readonly ?string $coin = null,
        public readonly ?string $network = null,
        public readonly ?string $chainFamily = null,
        public readonly ?string $contract = null,
    ) {}
}
