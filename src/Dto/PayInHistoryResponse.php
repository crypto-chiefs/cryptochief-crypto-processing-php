<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class PayInHistoryResponse extends BaseDto
{
    /**
     * @param PayIn[]|null $items
     */
    public function __construct(
        public readonly ?array $items = null,
        public readonly ?HistoryMeta $meta = null,
    ) {}
}
