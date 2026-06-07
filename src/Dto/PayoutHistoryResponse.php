<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class PayoutHistoryResponse extends BaseDto
{
    /**
     * @param PayoutInfo[]|null $items
     */
    public function __construct(
        public readonly ?array $items = null,
        public readonly ?HistoryMeta $meta = null,
    ) {}
}
