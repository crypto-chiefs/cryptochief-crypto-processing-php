<?php

declare(strict_types=1);

namespace CryptoChief\Processing;

use CryptoChief\Processing\Exception\CryptoChiefException;

/**
 * Canonical JSON serialization and request signing.
 *
 * The canonical form is deterministic:
 *
 *   - object keys sorted lexicographically by their UTF-8 bytes, recursively;
 *   - compact (no insignificant whitespace);
 *   - HTML-sensitive characters <, >, & and the U+2028 / U+2029 line / paragraph
 *     separators emitted as their JSON unicode escapes;
 *   - standard JSON escapes for ", \, and control characters (\n, \r, \t short
 *     forms; everything else below 0x20 as \u00XX, lowercase hex).
 *
 * The gateway re-derives the canonical form from the bytes it receives and checks
 * the signature against it, so the client must emit identical output.
 */
final class Sign
{
    /**
     * Canonical JSON string for a value. `null` collapses to an empty body, which signs as
     * md5(api_key).
     *
     * An empty array `[]` at the top level is treated as an empty object `{}` because PHP
     * does not distinguish empty list from empty dict. Inside a parent object an empty
     * array still renders as `[]`.
     *
     * @param mixed $value
     */
    public static function canonicalJson($value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value === []) {
            return '{}';
        }

        return self::encodeValue($value);
    }

    /**
     * Compute the Signature header for an already-canonical body:
     *
     *   hex(md5(base64(canonical_body) + api_key))
     *
     * An empty body signs as md5(api_key).
     */
    public static function sign(string $canonicalBody, string $apiKey): string
    {
        return md5(base64_encode($canonicalBody) . $apiKey);
    }

    /**
     * Canonicalize then sign a value.
     *
     * @param mixed $value
     * @return array{0:string,1:string} [canonical, signature]
     */
    public static function signValue($value, string $apiKey): array
    {
        $canonical = self::canonicalJson($value);

        return [$canonical, self::sign($canonical, $apiKey)];
    }

    /**
     * @param mixed $v
     */
    private static function encodeValue($v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            return self::encodeFloat($v);
        }
        if (is_string($v)) {
            return self::encodeString($v);
        }
        if (is_array($v)) {
            return self::encodeArray($v);
        }
        if (is_object($v)) {
            if ($v instanceof \BackedEnum) {
                return self::encodeValue($v->value);
            }
            if ($v instanceof \JsonSerializable) {
                return self::encodeValue($v->jsonSerialize());
            }
            if (method_exists($v, 'toWire')) {
                /** @var mixed $w */
                $w = $v->toWire();
                return self::encodeValue($w);
            }
            // Fall back to public properties as an object.
            $assoc = get_object_vars($v);
            return self::encodeObject($assoc);
        }

        throw new CryptoChiefException(
            'cryptochief: cannot canonicalize value of type ' . gettype($v)
        );
    }

    private static function encodeFloat(float $n): string
    {
        if (is_nan($n) || is_infinite($n)) {
            throw new CryptoChiefException(
                'cryptochief: cannot canonicalize non-finite number'
            );
        }
        // Integral floats below 1e21 render without the trailing decimal.
        if ((float) (int) $n === $n && abs($n) < 1e21) {
            return (string) (int) $n;
        }
        return (string) $n;
    }

    private static function encodeString(string $s): string
    {
        $out = '"';
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            $b = ord($s[$i]);
            // ASCII fast path.
            if ($b < 0x80) {
                $code = $b;
                $i += 1;
            } else {
                // Decode a UTF-8 code point so we can match the BMP separators U+2028/U+2029.
                if (($b & 0xE0) === 0xC0) {
                    $code = ($b & 0x1F) << 6 | (ord($s[$i + 1]) & 0x3F);
                    $i += 2;
                } elseif (($b & 0xF0) === 0xE0) {
                    $code = ($b & 0x0F) << 12
                        | (ord($s[$i + 1]) & 0x3F) << 6
                        | (ord($s[$i + 2]) & 0x3F);
                    $i += 3;
                } elseif (($b & 0xF8) === 0xF0) {
                    $code = ($b & 0x07) << 18
                        | (ord($s[$i + 1]) & 0x3F) << 12
                        | (ord($s[$i + 2]) & 0x3F) << 6
                        | (ord($s[$i + 3]) & 0x3F);
                    $i += 4;
                } else {
                    throw new CryptoChiefException('cryptochief: invalid UTF-8 sequence');
                }
                // Astral plane characters pass through as their original UTF-8 bytes.
                if ($code >= 0x10000) {
                    $out .= substr($s, $i - 4, 4);
                    continue;
                }
            }

            // Short-form escapes.
            switch ($code) {
                case 0x22: $out .= '\\"';     continue 2;
                case 0x5C: $out .= '\\\\';    continue 2;
                case 0x0A: $out .= '\\n';     continue 2;
                case 0x0D: $out .= '\\r';     continue 2;
                case 0x09: $out .= '\\t';     continue 2;
                case 0x3C: $out .= '\\u003c'; continue 2;
                case 0x3E: $out .= '\\u003e'; continue 2;
                case 0x26: $out .= '\\u0026'; continue 2;
                case 0x2028: $out .= '\\u2028'; continue 2;
                case 0x2029: $out .= '\\u2029'; continue 2;
            }

            if ($code < 0x20) {
                $out .= sprintf('\\u%04x', $code);
                continue;
            }

            if ($code < 0x80) {
                $out .= chr($code);
            } else {
                if ($code < 0x800) {
                    $out .= substr($s, $i - 2, 2);
                } else {
                    $out .= substr($s, $i - 3, 3);
                }
            }
        }
        $out .= '"';

        return $out;
    }

    /**
     * @param array<mixed> $arr
     */
    private static function encodeArray(array $arr): string
    {
        // List vs object detection: a list is a zero-indexed contiguous int-keyed array.
        if (self::isList($arr)) {
            $parts = [];
            foreach ($arr as $el) {
                $parts[] = self::encodeValue($el);
            }
            return '[' . implode(',', $parts) . ']';
        }

        return self::encodeObject($arr);
    }

    /**
     * @param array<mixed> $assoc
     */
    private static function encodeObject(array $assoc): string
    {
        // Drop nulls, then sort keys by UTF-8 byte order via byte-wise strcmp.
        $keys = [];
        foreach ($assoc as $k => $v) {
            if ($v === null) {
                continue;
            }
            $keys[] = (string) $k;
        }
        sort($keys, SORT_STRING);

        $parts = [];
        foreach ($keys as $k) {
            $parts[] = self::encodeString($k) . ':' . self::encodeValue($assoc[$k]);
        }

        return '{' . implode(',', $parts) . '}';
    }

    /**
     * @param array<mixed> $arr
     */
    private static function isList(array $arr): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($arr);
        }
        $i = 0;
        foreach ($arr as $k => $_) {
            if ($k !== $i) {
                return false;
            }
            $i++;
        }
        return true;
    }
}
