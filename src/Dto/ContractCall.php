<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * One instruction in a `contract`-type transaction request.
 *
 * Per-family encoding:
 *
 *   - EVM/TRON - `data` is hex calldata (0x...), single call.
 *   - TON - `data` is a base64 BoC body cell, single call, `bounce` defaults true.
 *   - Solana - `to` is the program id, `data` base64 instruction data, `accounts` lists
 *     the metas; multiple instructions allowed.
 */
final class ContractCall extends BaseDto
{
    /**
     * @param SolanaAccount[]|null $accounts
     */
    public function __construct(
        public readonly string $to,
        public readonly string $data,
        public readonly ?string $value = null,
        public readonly ?array $accounts = null,
        public readonly ?bool $bounce = null,
    ) {}
}
