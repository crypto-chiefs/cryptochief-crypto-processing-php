<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class SelectAssetRequest extends BaseDto
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $coin,
        public readonly string $network,
    ) {}
}
