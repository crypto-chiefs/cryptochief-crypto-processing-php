<?php

declare(strict_types=1);

namespace CryptoChief\Processing;

use CryptoChief\Processing\Exception\InvalidAmountException;

/**
 * Amount conversion helpers. Crypto amounts stay in decimal-string form: `float` loses
 * precision past 2^53 and binary rounding bites large token values.
 *
 * Inputs are decimal strings (e.g. "0.0001"), outputs are decimal base-unit strings
 * (e.g. "100000000000000" wei for 0.0001 ETH). Arbitrary-precision integer strings -
 * no rounding, no scientific notation.
 */
final class Amount
{
    /**
     * Convert a decimal human amount (e.g. "0.0001") to its base-unit decimal string.
     *
     * Precise to the last digit. Negative amounts and scientific notation are rejected.
     * Sub-base-unit precision is truncated, since it is meaningless on-chain.
     *
     *   Amount::humanToBase("1.5", 18)    === "1500000000000000000"
     *   Amount::humanToBase("0.0001", 8)  === "10000"
     */
    public static function humanToBase(string $human, int $decimals): string
    {
        $s = trim($human);
        if ($s === '') {
            throw new InvalidAmountException('empty');
        }
        if ($decimals < 0) {
            throw new InvalidAmountException('negative decimals ' . $decimals);
        }
        if (str_contains($s, 'e') || str_contains($s, 'E')) {
            throw new InvalidAmountException('scientific notation not allowed: ' . $human);
        }
        if (str_starts_with($s, '-')) {
            throw new InvalidAmountException('negative not allowed: ' . $human);
        }

        $dot = strpos($s, '.');
        if ($dot === false) {
            $intPart = $s;
            $fracPart = '';
        } else {
            $intPart = substr($s, 0, $dot);
            if ($intPart === '') {
                $intPart = '0';
            }
            $fracPart = substr($s, $dot + 1);
            if ($fracPart === '') {
                throw new InvalidAmountException($human);
            }
        }
        if (!self::isAsciiDigits($intPart) || ($fracPart !== '' && !self::isAsciiDigits($fracPart))) {
            throw new InvalidAmountException($human);
        }

        if (strlen($fracPart) > $decimals) {
            $fracPart = substr($fracPart, 0, $decimals);
        } else {
            $fracPart = str_pad($fracPart, $decimals, '0');
        }

        $combined = ltrim($intPart . $fracPart, '0');

        return $combined === '' ? '0' : $combined;
    }

    /**
     * Inverse of humanToBase: a base-unit decimal string to a decimal human string.
     *
     *   Amount::baseToHuman("1500000000000000000", 18) === "1.5"
     *   Amount::baseToHuman("0", 18)                   === "0"
     */
    public static function baseToHuman(string $base, int $decimals): string
    {
        $s = trim($base);
        if ($decimals < 0) {
            $decimals = 0;
        }
        if ($s === '') {
            throw new InvalidAmountException('empty');
        }
        $neg = false;
        if (str_starts_with($s, '-')) {
            $neg = true;
            $s = substr($s, 1);
        }
        if (!self::isAsciiDigits($s)) {
            throw new InvalidAmountException($base);
        }
        if ($decimals === 0) {
            return $neg ? '-' . $s : $s;
        }
        if (strlen($s) <= $decimals) {
            $s = str_repeat('0', $decimals - strlen($s) + 1) . $s;
        }
        $cut = strlen($s) - $decimals;
        $intPart = substr($s, 0, $cut);
        $fracPart = rtrim(substr($s, $cut), '0');
        $out = $fracPart === '' ? $intPart : $intPart . '.' . $fracPart;

        return $neg ? '-' . $out : $out;
    }

    /**
     * Convenience for `humanToBase($human, 9)` - the form `attached_ton` and
     * `forward_ton_amount` expect.
     */
    public static function nanoTon(string $human): string
    {
        return self::humanToBase($human, 9);
    }

    private static function isAsciiDigits(string $s): bool
    {
        if ($s === '') {
            return false;
        }
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if ($c < '0' || $c > '9') {
                return false;
            }
        }
        return true;
    }
}
