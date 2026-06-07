<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class StaticDepositHistoryQuery extends BaseDto
{
    public function __construct(
        public readonly ?string $address = null,
        public readonly ?string $status = null,
        public readonly ?string $coin = null,
        public readonly ?string $network = null,
        public readonly ?string $dateFrom = null,
        public readonly ?string $dateTo = null,
        public readonly ?int $page = null,
        public readonly ?int $pageSize = null,
    ) {}
}
