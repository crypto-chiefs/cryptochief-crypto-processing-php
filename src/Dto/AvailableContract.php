<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class AvailableContract extends BaseDto
{
    public function __construct(
        public readonly ?string $network = null,
        public readonly ?string $coin = null,
        public readonly ?string $contract = null,
        public readonly ?string $type = null,
        public readonly int $decimals = 0,
    ) {}
}
