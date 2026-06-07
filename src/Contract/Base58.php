<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Contract;

use CryptoChief\Processing\Exception\CryptoChiefException;

/**
 * Base58 (Bitcoin / Tron / Solana alphabet) using arbitrary-precision arithmetic. Shared
 * by the TRON address codec and Solana pubkey decoding. Uses bcmath for the integer
 * arithmetic.
 */
final class Base58
{
    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public static function encode(string $data): string
    {
        $len = strlen($data);
        $zeros = 0;
        while ($zeros < $len && $data[$zeros] === "\x00") {
            $zeros++;
        }
        $num = '0';
        for ($i = 0; $i < $len; $i++) {
            $num = bcadd(bcmul($num, '256'), (string) ord($data[$i]));
        }
        $out = '';
        while (bccomp($num, '0') > 0) {
            $rem = (int) bcmod($num, '58');
            $num = bcdiv($num, '58', 0);
            $out = self::ALPHABET[$rem] . $out;
        }
        return str_repeat(self::ALPHABET[0], $zeros) . $out;
    }

    public static function decode(string $s): string
    {
        if ($s === '') {
            throw new CryptoChiefException('cryptochief: base58: empty input');
        }
        $alphabet = self::ALPHABET;
        $zeros = 0;
        $len = strlen($s);
        while ($zeros < $len && $s[$zeros] === $alphabet[0]) {
            $zeros++;
        }
        $num = '0';
        for ($i = 0; $i < $len; $i++) {
            $pos = strpos($alphabet, $s[$i]);
            if ($pos === false) {
                throw new CryptoChiefException("cryptochief: base58: invalid char '{$s[$i]}'");
            }
            $num = bcadd(bcmul($num, '58'), (string) $pos);
        }
        $body = '';
        while (bccomp($num, '0') > 0) {
            $byte = (int) bcmod($num, '256');
            $body = chr($byte) . $body;
            $num = bcdiv($num, '256', 0);
        }
        return str_repeat("\x00", $zeros) . $body;
    }
}
