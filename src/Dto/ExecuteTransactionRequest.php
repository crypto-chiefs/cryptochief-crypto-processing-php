<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class ExecuteTransactionRequest extends BaseDto
{
    public function __construct(
        public readonly string $uuid,
        public readonly ?string $signedTxHex = null,
    ) {}
}
