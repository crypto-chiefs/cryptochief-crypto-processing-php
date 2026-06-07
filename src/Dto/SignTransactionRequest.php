<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * Two-phase sign request. `type` is one of: `native`, `token`, `contract`.
 *
 *   - native: `toAddress` + `value` (base units, e.g. wei)
 *   - token: `toAddress` + `value` + `contract`
 *   - contract: `calls` instead of transfer fields
 */
final class SignTransactionRequest extends BaseDto
{
    /**
     * @param ContractCall[]|null $calls
     */
    public function __construct(
        public readonly string $network,
        public readonly string $fromAddress,
        public readonly string $type,
        public readonly ?string $toAddress = null,
        public readonly ?string $value = null,
        public readonly ?string $contract = null,
        public readonly ?array $calls = null,
        public readonly ?string $urlCallback = null,
    ) {}
}
