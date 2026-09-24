<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class SignTransactionResponse extends BaseDto
{
    /**
     * @param string[] $supersededUuids EVM: the earlier unexecuted signatures from the same
     *        address that this one replaced; they turn `cancelled`. Empty when there were none.
     */
    public function __construct(
        public readonly string $uuid = '',
        public readonly string $status = '',
        public readonly ?string $signedTxHex = null,
        public readonly ?string $txHash = null,
        public readonly ?string $expiresAt = null,
        public readonly ?string $chainFamily = null,
        public readonly ?string $network = null,
        public readonly array $supersededUuids = [],
    ) {}
}
