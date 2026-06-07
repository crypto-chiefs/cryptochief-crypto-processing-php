<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class TransactionHistoryResponse extends BaseDto
{
    /**
     * @param TransactionInfo[]|null $items
     */
    public function __construct(
        public readonly ?array $items = null,
        public readonly ?HistoryMeta $meta = null,
    ) {}
}
