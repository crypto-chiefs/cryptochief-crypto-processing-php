<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * Common filter for history endpoints with simple pagination. Omitted (null) fields are
 * not sent.
 */
final class HistoryQuery extends BaseDto
{
    public function __construct(
        public readonly ?int $page = null,
        public readonly ?int $pageSize = null,
        public readonly ?string $status = null,
        public readonly ?string $coin = null,
        public readonly ?string $network = null,
        public readonly ?string $dateFrom = null,
        public readonly ?string $dateTo = null,
    ) {}
}
