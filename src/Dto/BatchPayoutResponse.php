<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class BatchPayoutResponse extends BaseDto
{
    /**
     * @param BatchItemResult[]|null $items
     */
    public function __construct(
        public readonly int $total = 0,
        public readonly int $accepted = 0,
        public readonly int $rejected = 0,
        public readonly ?array $items = null,
        public readonly ?string $batchUuid = null,
    ) {}
}
