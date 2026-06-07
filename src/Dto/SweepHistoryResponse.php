<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class SweepHistoryResponse extends BaseDto
{
    /**
     * @param Sweep[]|null $items
     */
    public function __construct(
        public readonly ?array $items = null,
        public readonly ?HistoryMeta $meta = null,
    ) {}
}
