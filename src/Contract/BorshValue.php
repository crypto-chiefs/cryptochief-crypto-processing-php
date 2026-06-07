<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Contract;

/**
 * A value paired with its Borsh encoding, ready to concatenate. Returned by the
 * Borsh::u8 / Borsh::string / etc. constructors and consumed by Borsh::encodeAnchorInstruction.
 */
final class BorshValue
{
    public function __construct(private readonly string $data) {}

    public function encode(): string
    {
        return $this->data;
    }
}
