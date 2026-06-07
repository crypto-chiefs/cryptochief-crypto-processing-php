<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class AvailableContractsResponse extends BaseDto
{
    /**
     * @param AvailableContract[]|null $items
     */
    public function __construct(
        public readonly ?array $items = null,
    ) {}
}
