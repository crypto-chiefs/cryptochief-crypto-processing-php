<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class SolanaAccount extends BaseDto
{
    /** Base58 account address. */
    public readonly string $address;

    /**
     * @param string|null $pubkey Deprecated: use `$address`. Sent as `address`.
     */
    public function __construct(
        string $address = '',
        public readonly bool $isSigner = false,
        public readonly bool $isWritable = false,
        ?string $pubkey = null,
    ) {
        $this->address = $address !== '' ? $address : (string) $pubkey;
        if ($this->address === '') {
            throw new \InvalidArgumentException('SolanaAccount: address is required');
        }
    }
}
