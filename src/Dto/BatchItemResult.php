<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class BatchItemResult extends BaseDto
{
    public function __construct(
        public readonly int $index = 0,
        public readonly ?string $orderId = null,
        public readonly ?string $status = null,
        public readonly ?string $uuid = null,
        public readonly ?string $error = null,
    ) {}
}
