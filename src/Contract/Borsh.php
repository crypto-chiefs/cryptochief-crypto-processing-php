<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Contract;

use CryptoChief\Processing\Exception\BorshException;

/**
 * Borsh encoding + Anchor instruction building for Solana.
 *
 * Anchor instruction data is [8-byte discriminator][Borsh-encoded args]. Borsh has no
 * on-wire type tags, so each argument's type must be described explicitly through the
 * `Borsh::*` constructors. Each returns a BorshValue; pass them to
 * `Borsh::encodeAnchorInstruction()`.
 */
final class Borsh
{
    public static function u8(int $n): BorshValue   { return new BorshValue(self::le((string) $n, 1)); }
    public static function u16(int $n): BorshValue  { return new BorshValue(self::le((string) $n, 2)); }
    public static function u32(int $n): BorshValue  { return new BorshValue(self::le((string) $n, 4)); }
    public static function u64(int|string $n): BorshValue { return new BorshValue(self::le((string) $n, 8)); }

    public static function i8(int $n): BorshValue  { return self::u8($n); }
    public static function i16(int $n): BorshValue { return self::u16($n); }
    public static function i32(int $n): BorshValue { return self::u32($n); }
    public static function i64(int|string $n): BorshValue { return self::u64($n); }

    /**
     * 128-bit unsigned little-endian. Must be non-negative and < 2^128.
     */
    public static function u128(int|string $n): BorshValue
    {
        $s = (string) $n;
        if (bccomp($s, '0') < 0) {
            throw new BorshException('u128 negative');
        }
        if (bccomp($s, bcpow('2', '128')) >= 0) {
            throw new BorshException('u128 overflow');
        }
        return new BorshValue(self::le($s, 16));
    }

    public static function bool(bool $b): BorshValue
    {
        return new BorshValue($b ? "\x01" : "\x00");
    }

    /**
     * UTF-8 string: 4-byte LE length prefix + bytes.
     */
    public static function string(string $s): BorshValue
    {
        return new BorshValue(self::le((string) strlen($s), 4) . $s);
    }

    /**
     * Raw byte slice: 4-byte LE length prefix + bytes (same wire form as a string).
     */
    public static function bytes(string $b): BorshValue
    {
        return new BorshValue(self::le((string) strlen($b), 4) . $b);
    }

    /**
     * Fixed-length bytes with NO length prefix (Anchor's `[u8; N]`).
     */
    public static function fixedBytes(string $b, int $n): BorshValue
    {
        if (strlen($b) !== $n) {
            throw new BorshException("fixedBytes: expected {$n} bytes, got " . strlen($b));
        }
        return new BorshValue($b);
    }

    /**
     * A Solana 32-byte pubkey (base58 string or raw 32 bytes).
     */
    public static function pubkey(string $pk): BorshValue
    {
        return new BorshValue(self::decodeSolanaPubkey($pk));
    }

    /**
     * Nullable value: null -> 0x00; otherwise 0x01 + inner encoding.
     */
    public static function option(?BorshValue $inner): BorshValue
    {
        if ($inner === null) {
            return new BorshValue("\x00");
        }
        return new BorshValue("\x01" . $inner->encode());
    }

    /**
     * Homogeneous Vec<T>: 4-byte LE length + elements.
     *
     * @param BorshValue[] $items
     */
    public static function vec(array $items): BorshValue
    {
        $body = '';
        foreach ($items as $it) {
            $body .= $it->encode();
        }
        return new BorshValue(self::le((string) count($items), 4) . $body);
    }

    /**
     * Heterogeneous struct / tuple: fields in order, no length prefix.
     */
    public static function struct(BorshValue ...$fields): BorshValue
    {
        $body = '';
        foreach ($fields as $f) {
            $body .= $f->encode();
        }
        return new BorshValue($body);
    }

    /**
     * The 8-byte Anchor instruction discriminator: sha256("global:" + method)[:8].
     */
    public static function anchorDiscriminator(string $method): string
    {
        return substr(hash('sha256', 'global:' . $method, true), 0, 8);
    }

    /**
     * Raw Anchor instruction data: 8-byte discriminator + Borsh-encoded args.
     */
    public static function encodeAnchorInstruction(string $method, BorshValue ...$args): string
    {
        $out = self::anchorDiscriminator($method);
        foreach ($args as $a) {
            $out .= $a->encode();
        }
        return $out;
    }

    /**
     * Decode a Solana pubkey (base58 string or raw 32 bytes) to its 32-byte form.
     */
    public static function decodeSolanaPubkey(string $pk): string
    {
        if (strlen($pk) === 32 && self::looksBinary($pk)) {
            return $pk;
        }
        $raw = Base58::decode($pk);
        if (strlen($raw) !== 32) {
            throw new BorshException('solana pubkey: decoded length ' . strlen($raw) . ', want 32');
        }
        return $raw;
    }

    /**
     * Little-endian encoding of an unsigned integer (decimal string) into $width bytes.
     */
    private static function le(string $value, int $width): string
    {
        $value = (string) $value;
        if (str_starts_with($value, '-')) {
            // Two's complement: add 2^(8*width)
            $value = bcadd($value, bcpow('2', (string) (8 * $width)));
        }
        $mod = bcpow('2', (string) (8 * $width));
        if (bccomp($value, $mod) >= 0) {
            $value = bcmod($value, $mod);
        }
        $out = '';
        for ($i = 0; $i < $width; $i++) {
            $byte = (int) bcmod($value, '256');
            $out .= chr($byte);
            $value = bcdiv($value, '256', 0);
        }
        return $out;
    }

    /**
     * Heuristic check used to distinguish a raw 32-byte pubkey from its base58 string.
     * base58 encodings of 32 bytes are 43-44 ASCII chars, so a 32-byte input with any
     * byte > 0x7f cannot be a base58 string.
     */
    private static function looksBinary(string $s): bool
    {
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            if (ord($s[$i]) >= 0x80) {
                return true;
            }
        }
        return false;
    }
}
