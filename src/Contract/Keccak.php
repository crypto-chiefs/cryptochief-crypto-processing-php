<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Contract;

use kornrunner\Keccak as KornrunnerKeccak;

/**
 * Wrapper around kornrunner/keccak. Ethereum selectors use the legacy Keccak-256, not
 * the FIPS-202 SHA-3-256 that ships in PHP's stdlib - they differ in their padding byte
 * (0x01 vs 0x06).
 */
final class Keccak
{
    /**
     * Keccak-256 hash of raw bytes, returned as raw 32 bytes.
     */
    public static function hash256(string $data): string
    {
        return (string) hex2bin(KornrunnerKeccak::hash($data, 256));
    }

    /**
     * Keccak-256 hash, returned as a lowercase hex string (no 0x prefix).
     */
    public static function hash256Hex(string $data): string
    {
        return KornrunnerKeccak::hash($data, 256);
    }
}
