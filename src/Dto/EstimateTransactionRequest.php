<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * Network-fee estimate request. `type` is one of: `native`, `token`.
 *
 *   - native: `toAddress` + `value` (base units, e.g. wei)
 *   - token: `toAddress` + `value` + `contract`
 *
 * Same transfer fields as `SignTransactionRequest`, minus `urlCallback` and `calls` -
 * estimation never signs or broadcasts, and contract calls are not estimable (the API
 * answers 400 `CONTRACT_ESTIMATE_UNSUPPORTED`).
 */
final class EstimateTransactionRequest extends BaseDto
{
    public function __construct(
        public readonly string $network,
        public readonly string $fromAddress,
        public readonly string $type = 'native',
        public readonly ?string $toAddress = null,
        public readonly ?string $value = null,
        public readonly ?string $contract = null,
    ) {}
}
