<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class SweepHistoryQuery extends BaseDto
{
    public function __construct(
        public readonly ?string $mode = null,
        public readonly ?int $page = null,
        public readonly ?int $pageSize = null,
    ) {}
}
