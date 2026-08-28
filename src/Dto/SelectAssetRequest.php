<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class SelectAssetRequest extends BaseDto
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $coin,
        public readonly string $network,
        /**
         * Pin the order's transit deposit wallet to the given project master wallet; see
         * {@see CreatePayInRequest}. A value here overrides one supplied at order create.
         */
        public readonly ?string $masterWalletAddress = null,
    ) {}
}
