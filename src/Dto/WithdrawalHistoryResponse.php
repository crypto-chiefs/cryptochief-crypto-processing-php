<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class WithdrawalHistoryResponse extends BaseDto
{
    /**
     * @param Withdrawal[]|null $items
     */
    public function __construct(
        public readonly ?array $items = null,
        public readonly ?HistoryMeta $meta = null,
    ) {}
}
