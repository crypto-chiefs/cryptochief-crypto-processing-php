<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class ListWalletsResponse extends BaseDto
{
    /**
     * @param Wallet[]|null $items
     */
    public function __construct(
        public readonly ?array $items = null,
    ) {}
}
