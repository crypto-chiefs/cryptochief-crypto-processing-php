<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Contract;

use CryptoChief\Processing\Exception\CryptoChiefException;

/**
 * TRON address conversion between Base58Check (`T...`) and 0x41-prefixed hex.
 */
final class TronAddress
{
    /**
     * Convert a TRON base58 address ("T...") to its 0x41-prefixed 21-byte hex.
     *
     * Validates the Base58Check (double-SHA-256) checksum.
     *
     *   TronAddress::toHex("TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t")
     *     === "0x41a614f803b6fd780986a42c78ec9c7f77e6ded13c"
     */
    public static function toHex(string $base58Addr): string
    {
        $decoded = Base58::decode(trim($base58Addr));
        if (strlen($decoded) !== 25) {
            throw new CryptoChiefException(
                'cryptochief/tron: decoded length ' . strlen($decoded) . ', want 25'
            );
        }
        $payload = substr($decoded, 0, 21);
        $checksum = substr($decoded, 21);

        if (ord($payload[0]) !== 0x41) {
            throw new CryptoChiefException(sprintf(
                'cryptochief/tron: leading byte 0x%02x, want 0x41',
                ord($payload[0])
            ));
        }
        if ($checksum !== substr(self::sha256d($payload), 0, 4)) {
            throw new CryptoChiefException('cryptochief/tron: checksum mismatch');
        }
        return '0x' . bin2hex($payload);
    }

    /**
     * Convert a 20-byte EVM-style hex (or a 0x41-prefixed 21-byte TRON hex) to base58. A
     * 20-byte input is prefixed with 0x41 automatically.
     */
    public static function fromHex(string $hexAddr): string
    {
        $s = trim($hexAddr);
        if (strncasecmp($s, '0x', 2) === 0) {
            $s = substr($s, 2);
        }
        $raw = @hex2bin($s);
        if ($raw === false) {
            throw new CryptoChiefException("cryptochief/tron: bad hex '{$hexAddr}'");
        }
        if (strlen($raw) === 20) {
            $payload = "\x41" . $raw;
        } elseif (strlen($raw) === 21) {
            if (ord($raw[0]) !== 0x41) {
                throw new CryptoChiefException(sprintf(
                    'cryptochief/tron: 21-byte input must start with 0x41, got 0x%02x',
                    ord($raw[0])
                ));
            }
            $payload = $raw;
        } else {
            throw new CryptoChiefException(
                'cryptochief/tron: want 20- or 21-byte hex address, got ' . strlen($raw) . ' bytes'
            );
        }
        $checksum = substr(self::sha256d($payload), 0, 4);
        return Base58::encode($payload . $checksum);
    }

    private static function sha256d(string $data): string
    {
        return hash('sha256', hash('sha256', $data, true), true);
    }
}
