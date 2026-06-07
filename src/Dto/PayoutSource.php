<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class PayoutSource extends BaseDto
{
    public function __construct(
        public readonly ?string $address = null,
        public readonly ?string $amount = null,
        public readonly ?string $coin = null,
    ) {}
}
