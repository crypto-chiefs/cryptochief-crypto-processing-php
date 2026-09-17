<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Compares request bodies as JSON values: member order and escaping do not matter, `{}`
 * and `[]` differ, and scalars keep their type.
 */
final class JsonBody
{
    public static function assertSameValue(string $expectedJson, string $actualBody, string $message = ''): void
    {
        if ($expectedJson === '' || $actualBody === '') {
            Assert::assertSame($expectedJson, $actualBody, $message);
            return;
        }
        Assert::assertSame(
            self::normalize(json_decode($expectedJson, false, 512, JSON_THROW_ON_ERROR)),
            self::normalize(json_decode($actualBody, false, 512, JSON_THROW_ON_ERROR)),
            $message !== '' ? $message : $actualBody,
        );
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $members = [];
            foreach (get_object_vars($value) as $key => $member) {
                $members[(string) $key] = self::normalize($member);
            }
            ksort($members, SORT_STRING);

            return ['object' => $members];
        }
        if (is_array($value)) {
            return ['list' => array_map(self::normalize(...), $value)];
        }

        return $value;
    }
}
