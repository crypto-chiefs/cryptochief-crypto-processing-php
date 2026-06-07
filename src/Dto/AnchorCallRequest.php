<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

use CryptoChief\Processing\Contract\BorshValue;

final class AnchorCallRequest
{
    /**
     * @param BorshValue[] $args
     * @param SolanaAccount[] $accounts
     */
    public function __construct(
        public readonly string $network,
        public readonly string $fromAddress,
        public readonly string $program,
        public readonly string $method,
        public readonly array $args = [],
        public readonly array $accounts = [],
        public readonly ?string $urlCallback = null,
    ) {}
}
