<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Contract;

use CryptoChief\Processing\Exception\EvmAbiException;

/**
 * Solidity ABI encoder. Turns a function signature + argument values into calldata.
 * Shared by EVM and TRON (TRON uses the same ABI).
 *
 * Supported types: uint<M> / int<M> (M in 8..256, step 8; bare `uint` / `int` alias to
 * 256), `address` (0x hex, 0x41 TRON hex, or T... base58), `bool`, `bytes`, `bytes<N>`
 * (N in 1..32), `string`, fixed / dynamic arrays T[] / T[N].
 *
 * Integer args accept int or a decimal / "0x..." hex string; bytes args accept a binary
 * string or "0x..." hex; address / string args take strings; arrays take arrays.
 */
final class EvmAbi
{
    /**
     * The 4-byte function selector for a Solidity signature.
     */
    public static function selector(string $signature): string
    {
        return substr(Keccak::hash256(self::canonicalSignature($signature)), 0, 4);
    }

    /**
     * Build ABI calldata (selector + encoded args) as raw bytes.
     */
    public static function encodeCall(string $signature, mixed ...$args): string
    {
        [$_, $typeStrs] = self::parseSignature($signature);
        if (count($typeStrs) !== count($args)) {
            throw new EvmAbiException(
                'signature has ' . count($typeStrs) . ' args, got ' . count($args)
            );
        }
        $parsed = [];
        foreach ($typeStrs as $i => $s) {
            try {
                $parsed[] = self::parseType($s);
            } catch (EvmAbiException $err) {
                throw new EvmAbiException("arg {$i} ({$s}): " . self::stripPrefix($err->getMessage()));
            }
        }
        return self::selector($signature) . self::encodeComponents($parsed, $args);
    }

    /**
     * Build ABI calldata as a 0x...-hex string (the form the `data` field expects).
     */
    public static function encodeCallHex(string $signature, mixed ...$args): string
    {
        return '0x' . bin2hex(self::encodeCall($signature, ...$args));
    }

    /**
     * Canonical form keccak hashes against (no spaces, no parameter names).
     */
    public static function canonicalSignature(string $sig): string
    {
        $openI = strpos($sig, '(');
        $closeI = strrpos($sig, ')');
        if ($openI === false || $closeI === false || $closeI < $openI) {
            return str_replace(' ', '', $sig);
        }
        $name = trim(substr($sig, 0, $openI));
        $body = trim(substr($sig, $openI + 1, $closeI - $openI - 1));
        if ($body === '') {
            return $name . '()';
        }
        $parts = array_map(fn ($p) => self::stripParamName($p), explode(',', $body));
        return $name . '(' . implode(',', $parts) . ')';
    }

    /**
     * @return array{0:string, 1:string[]}
     */
    private static function parseSignature(string $sig): array
    {
        $openI = strpos($sig, '(');
        $closeI = strrpos($sig, ')');
        if ($openI === false || $closeI === false || $closeI < $openI) {
            throw new EvmAbiException("bad signature '{$sig}'");
        }
        $name = trim(substr($sig, 0, $openI));
        if ($name === '') {
            throw new EvmAbiException('signature missing name');
        }
        $body = trim(substr($sig, $openI + 1, $closeI - $openI - 1));
        if ($body === '') {
            return [$name, []];
        }
        $parts = array_map(fn ($p) => self::stripParamName($p), explode(',', $body));
        return [$name, $parts];
    }

    private static function stripParamName(string $p): string
    {
        $p = trim($p);
        $sp = strpos($p, ' ');
        if ($sp !== false) {
            $p = trim(substr($p, 0, $sp));
        }
        return self::expandAlias($p);
    }

    private static function expandAlias(string $t): string
    {
        $i = strrpos($t, '[');
        if ($i !== false && $i > 0) {
            return self::expandAlias(substr($t, 0, $i)) . substr($t, $i);
        }
        return match ($t) {
            'uint' => 'uint256',
            'int'  => 'int256',
            'byte' => 'bytes1',
            default => $t,
        };
    }

    /**
     * @return array{kind:string, size:int, element:?array<string,mixed>}
     */
    private static function parseType(string $raw): array
    {
        $t = trim($raw);
        if ($t === '') {
            throw new EvmAbiException('empty type');
        }
        if (str_ends_with($t, ']')) {
            $openI = strrpos($t, '[');
            if ($openI === false) {
                throw new EvmAbiException("malformed type '{$t}'");
            }
            $element = self::parseType(substr($t, 0, $openI));
            $span = substr($t, $openI + 1, strlen($t) - $openI - 2);
            $size = -1;
            if ($span !== '') {
                if (!ctype_digit($span)) {
                    throw new EvmAbiException("bad array size in '{$t}'");
                }
                $size = (int) $span;
            }
            return ['kind' => 'array', 'size' => $size, 'element' => $element];
        }
        if (str_starts_with($t, 'uint')) {
            return ['kind' => 'uint', 'size' => self::parseIntBits(substr($t, 4), 'uint'), 'element' => null];
        }
        if (str_starts_with($t, 'int')) {
            return ['kind' => 'int', 'size' => self::parseIntBits(substr($t, 3), 'int'), 'element' => null];
        }
        if ($t === 'address') {
            return ['kind' => 'address', 'size' => 0, 'element' => null];
        }
        if ($t === 'bool') {
            return ['kind' => 'bool', 'size' => 0, 'element' => null];
        }
        if ($t === 'string') {
            return ['kind' => 'string', 'size' => 0, 'element' => null];
        }
        if ($t === 'bytes') {
            return ['kind' => 'bytes', 'size' => 0, 'element' => null];
        }
        if (str_starts_with($t, 'bytes')) {
            $rest = substr($t, 5);
            if (!ctype_digit($rest)) {
                throw new EvmAbiException("invalid fixed bytes type '{$t}'");
            }
            $n = (int) $rest;
            if ($n < 1 || $n > 32) {
                throw new EvmAbiException("invalid fixed bytes type '{$t}'");
            }
            return ['kind' => 'bytesN', 'size' => $n, 'element' => null];
        }
        throw new EvmAbiException("unsupported type '{$t}'");
    }

    private static function parseIntBits(string $s, string $kind): int
    {
        if ($s === '') {
            return 256;
        }
        if (!ctype_digit($s)) {
            throw new EvmAbiException("invalid {$kind} width '{$s}'");
        }
        $bits = (int) $s;
        if ($bits <= 0 || $bits > 256 || $bits % 8 !== 0) {
            throw new EvmAbiException("invalid {$kind} width '{$s}'");
        }
        return $bits;
    }

    /**
     * @param array{kind:string,size:int,element:?array<string,mixed>} $t
     */
    private static function isDynamic(array $t): bool
    {
        if ($t['kind'] === 'bytes' || $t['kind'] === 'string') {
            return true;
        }
        if ($t['kind'] === 'array') {
            /** @var array{kind:string,size:int,element:?array<string,mixed>} $element */
            $element = $t['element'];
            return $t['size'] < 0 || self::isDynamic($element);
        }
        return false;
    }

    /**
     * @param array<int, array{kind:string,size:int,element:?array<string,mixed>}> $types
     * @param array<int, mixed> $args
     */
    private static function encodeComponents(array $types, array $args): string
    {
        $tails = [];
        foreach ($types as $i => $t) {
            try {
                $tails[] = self::encodeOne($t, $args[$i]);
            } catch (EvmAbiException $err) {
                throw new EvmAbiException("arg {$i}: " . self::stripPrefix($err->getMessage()));
            }
        }
        $headSize = 32 * count($types);
        $offsets = array_fill(0, count($types), 0);
        $cursor = $headSize;
        foreach ($types as $i => $t) {
            if (self::isDynamic($t)) {
                $offsets[$i] = $cursor;
                $cursor += strlen($tails[$i]);
            }
        }
        $heads = [];
        foreach ($types as $i => $t) {
            $heads[] = self::isDynamic($t) ? self::uint256Bytes((string) $offsets[$i]) : $tails[$i];
        }
        $dynamicTails = '';
        foreach ($types as $i => $t) {
            if (self::isDynamic($t)) {
                $dynamicTails .= $tails[$i];
            }
        }
        return implode('', $heads) . $dynamicTails;
    }

    /**
     * @param array{kind:string,size:int,element:?array<string,mixed>} $t
     */
    private static function encodeOne(array $t, mixed $v): string
    {
        switch ($t['kind']) {
            case 'uint':
                return self::uint256Bytes(self::toBigUint($v, $t['size']));
            case 'int':
                return self::uint256Bytes(self::toBigInt($v));
            case 'address':
                return str_repeat("\x00", 12) . self::normalizeEvmAddress($v);
            case 'bool':
                if (!is_bool($v)) {
                    throw new EvmAbiException('bool: want bool, got ' . gettype($v));
                }
                return str_repeat("\x00", 31) . ($v ? "\x01" : "\x00");
            case 'bytesN':
                $b = self::toBytes($v);
                if (strlen($b) !== $t['size']) {
                    throw new EvmAbiException("bytes{$t['size']}: expected {$t['size']} bytes, got " . strlen($b));
                }
                return $b . str_repeat("\x00", 32 - $t['size']);
            case 'bytes':
                return self::encodeDynBytes(self::toBytes($v));
            case 'string':
                if (!is_string($v)) {
                    throw new EvmAbiException('string: want string, got ' . gettype($v));
                }
                return self::encodeDynBytes($v);
            case 'array':
                if (!is_array($v)) {
                    throw new EvmAbiException('array: want array, got ' . gettype($v));
                }
                $count = count($v);
                if ($t['size'] >= 0 && $count !== $t['size']) {
                    throw new EvmAbiException("fixed array T[{$t['size']}]: expected {$t['size']} items, got {$count}");
                }
                /** @var array{kind:string,size:int,element:?array<string,mixed>} $element */
                $element = $t['element'];
                $inner = array_fill(0, $count, $element);
                $body = self::encodeComponents($inner, array_values($v));
                if ($t['size'] < 0) {
                    return self::uint256Bytes((string) $count) . $body;
                }
                return $body;
        }
        throw new EvmAbiException('cannot encode kind ' . $t['kind']);
    }

    /**
     * 20 raw bytes for an address. Accepts 0x hex, 0x41-prefixed TRON hex, or T... base58.
     */
    private static function normalizeEvmAddress(mixed $value): string
    {
        if (!is_string($value)) {
            throw new EvmAbiException('address: want string, got ' . gettype($value));
        }
        $s = trim($value);
        if ($s === '') {
            throw new EvmAbiException('address: empty');
        }
        if (
            strlen($s) >= 30
            && ($s[0] === 'T' || $s[0] === 't')
            && strncasecmp($s, '0x', 2) !== 0
        ) {
            $hex = substr(TronAddress::toHex($s), 2);
            $raw = (string) hex2bin($hex);
            if (strlen($raw) === 21 && ord($raw[0]) === 0x41) {
                return substr($raw, 1);
            }
            if (strlen($raw) === 20) {
                return $raw;
            }
            throw new EvmAbiException('address: unexpected TRON length ' . strlen($raw));
        }
        if (strncasecmp($s, '0x', 2) === 0) {
            $s = substr($s, 2);
        }
        if (strlen($s) === 42 && substr($s, 0, 2) === '41') {
            $s = substr($s, 2);
        }
        if (strlen($s) !== 40) {
            throw new EvmAbiException('address: want 20 hex bytes, got ' . strlen($s) . ' chars');
        }
        $raw = @hex2bin($s);
        if ($raw === false) {
            throw new EvmAbiException('address: bad hex');
        }
        return $raw;
    }

    private static function toBigUint(mixed $v, int $bits): string
    {
        $n = self::toBigInt($v);
        if (bccomp($n, '0') < 0) {
            throw new EvmAbiException("uint{$bits}: negative value {$n}");
        }
        $max = bcpow('2', (string) $bits);
        if (bccomp($n, $max) >= 0) {
            throw new EvmAbiException("uint{$bits}: value {$n} exceeds max");
        }
        return $n;
    }

    /**
     * @return string decimal-string integer (possibly negative)
     */
    private static function toBigInt(mixed $v): string
    {
        if (is_bool($v)) {
            throw new EvmAbiException('integer: got bool');
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_string($v)) {
            $s = trim($v);
            if ($s === '') {
                throw new EvmAbiException('integer: empty string');
            }
            if (strncasecmp($s, '0x', 2) === 0) {
                $hexpart = substr($s, 2);
                if (!ctype_xdigit($hexpart)) {
                    throw new EvmAbiException("invalid integer string '{$v}'");
                }
                return self::hexToDec($hexpart);
            }
            $neg = false;
            $body = $s;
            if (str_starts_with($body, '-')) {
                $neg = true;
                $body = substr($body, 1);
            }
            if (!ctype_digit($body)) {
                throw new EvmAbiException("invalid integer string '{$v}'");
            }
            return $neg ? '-' . $body : $body;
        }
        throw new EvmAbiException('integer: unsupported type ' . gettype($v));
    }

    private static function hexToDec(string $hex): string
    {
        $dec = '0';
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i++) {
            $digit = hexdec($hex[$i]);
            $dec = bcadd(bcmul($dec, '16'), (string) $digit);
        }
        return $dec;
    }

    private static function toBytes(mixed $v): string
    {
        if (is_string($v)) {
            $s = trim($v);
            if (strncasecmp($s, '0x', 2) === 0) {
                $hexpart = substr($s, 2);
                $raw = @hex2bin($hexpart);
                if ($raw === false) {
                    throw new EvmAbiException("bytes: bad hex '{$v}'");
                }
                return $raw;
            }
            return $v;
        }
        throw new EvmAbiException('bytes: unsupported type ' . gettype($v));
    }

    private static function uint256Bytes(string $decimal): string
    {
        // Two's complement for negatives, modulo 2^256.
        $mod = bcpow('2', '256');
        if (bccomp($decimal, '0') < 0) {
            $decimal = bcadd($decimal, $mod);
        } elseif (bccomp($decimal, $mod) >= 0) {
            $decimal = bcmod($decimal, $mod);
        }
        $hex = '';
        while (bccomp($decimal, '0') > 0) {
            $byte = (int) bcmod($decimal, '256');
            $hex = sprintf('%02x', $byte) . $hex;
            $decimal = bcdiv($decimal, '256', 0);
        }
        $hex = str_pad($hex, 64, '0', STR_PAD_LEFT);
        return (string) hex2bin($hex);
    }

    private static function encodeDynBytes(string $b): string
    {
        $padded = $b;
        $rem = strlen($b) % 32;
        if ($rem !== 0) {
            $padded .= str_repeat("\x00", 32 - $rem);
        }
        return self::uint256Bytes((string) strlen($b)) . $padded;
    }

    private static function stripPrefix(string $msg): string
    {
        $prefix = 'cryptochief/evm: ';
        return str_starts_with($msg, $prefix) ? substr($msg, strlen($prefix)) : $msg;
    }
}
