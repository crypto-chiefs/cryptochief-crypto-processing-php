<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * Pagination envelope returned by every history endpoint.
 */
final class HistoryMeta extends BaseDto
{
    public function __construct(
        public readonly int $page = 0,
        public readonly int $pageSize = 0,
        public readonly int $total = 0,
        public readonly ?int $totalPages = null,
    ) {}
}
