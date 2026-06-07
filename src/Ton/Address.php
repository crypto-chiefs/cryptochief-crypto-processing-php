<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Ton;

use CryptoChief\Processing\Exception\CryptoChiefException;

/**
 * Offline parsing / validation of TON addresses. Three input forms wrap the same 33 bytes
 * (1 tag + 1 workchain + 32 hash):
 *
 *   - user-friendly bounceable     EQ... (mainnet) / kQ... (testnet)
 *   - user-friendly non-bounceable UQ... (mainnet) / 0Q... (testnet)
 *   - raw                          <workchain>:<32-byte-hex>
 *
 * The user-friendly forms carry a 2-byte CRC16-XMODEM checksum that this parser
 * validates. No network access.
 */
final class Address
{
    /**
     * CRC-16/XMODEM (poly 0x1021, init 0x0000, non-reflected) - TON's checksum.
     */
    public static function crc16Xmodem(string $data): int
    {
        $crc = 0;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($j = 0; $j < 8; $j++) {
                if (($crc & 0x8000) !== 0) {
                    $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFF;
                }
            }
        }
        return $crc & 0xFFFF;
    }

    /**
     * Parse any of the three TON address forms; raises on CRC / length errors.
     */
    public static function parse(string $value): TonAddress
    {
        $s = trim($value);
        if ($s === '') {
            throw new CryptoChiefException('cryptochief/ton: empty address');
        }
        $colon = strpos($s, ':');
        if ($colon !== false && $colon > 0) {
            return self::parseRaw($s, $colon);
        }
        return self::parseFriendly($s);
    }

    /**
     * Render the user-friendly form (URL-safe base64, no padding).
     */
    public static function toString(TonAddress $a): string
    {
        $tag = $a->bounceable ? 0x11 : 0x51;
        if ($a->testnet) {
            $tag |= 0x80;
        }
        $buf = chr($tag) . chr($a->workchain & 0xFF) . substr($a->hash, 0, 32);
        $crc = self::crc16Xmodem($buf);
        $buf .= chr(($crc >> 8) & 0xFF) . chr($crc & 0xFF);
        return rtrim(strtr(base64_encode($buf), '+/', '-_'), '=');
    }

    /**
     * Render the raw `workchain:hex` form.
     */
    public static function toRaw(TonAddress $a): string
    {
        return $a->workchain . ':' . bin2hex($a->hash);
    }

    private static function parseRaw(string $s, int $colon): TonAddress
    {
        $wcStr = substr($s, 0, $colon);
        if (!preg_match('/^-?\d+$/', $wcStr)) {
            throw new CryptoChiefException("cryptochief/ton: bad raw workchain '{$wcStr}'");
        }
        $wc = (int) $wcStr;
        if ($wc < -128 || $wc > 127) {
            throw new CryptoChiefException("cryptochief/ton: bad raw workchain '{$wcStr}'");
        }
        $hashHex = substr($s, $colon + 1);
        if (strlen($hashHex) !== 64) {
            throw new CryptoChiefException(
                'cryptochief/ton: hash hex length ' . strlen($hashHex) . ', want 64'
            );
        }
        $h = @hex2bin($hashHex);
        if ($h === false) {
            throw new CryptoChiefException('cryptochief/ton: bad hash hex');
        }
        return new TonAddress(workchain: $wc, hash: $h, bounceable: true, testnet: false);
    }

    private static function parseFriendly(string $s): TonAddress
    {
        if (strlen($s) !== 48) {
            throw new CryptoChiefException(
                'cryptochief/ton: user-friendly address length ' . strlen($s) . ', want 48'
            );
        }
        // URL-safe base64 -> standard alphabet + padding.
        $std = strtr($s, '-_', '+/');
        $std .= str_repeat('=', (4 - strlen($std) % 4) % 4);
        $raw = base64_decode($std, true);
        if ($raw === false || strlen($raw) !== 36) {
            throw new CryptoChiefException(
                'cryptochief/ton: bad base64 address (decoded length ' .
                ($raw === false ? '0' : strlen($raw)) . ', want 36)'
            );
        }
        $want = self::crc16Xmodem(substr($raw, 0, 34));
        $got = (ord($raw[34]) << 8) | ord($raw[35]);
        if ($want !== $got) {
            throw new CryptoChiefException('cryptochief/ton: CRC mismatch');
        }
        $tag = ord($raw[0]);
        $wcByte = ord($raw[1]);
        $workchain = $wcByte > 127 ? $wcByte - 256 : $wcByte; // int8

        return new TonAddress(
            workchain: $workchain,
            hash: substr($raw, 2, 32),
            bounceable: ($tag & 0x40) === 0,
            testnet: ($tag & 0x80) !== 0,
        );
    }
}
