<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests\Support;

/**
 * The HMAC v1 vector files, copied unchanged from the gateway and the webhook service.
 *
 * Every read goes through here, so a copy that drifted from the reference fails on the spot
 * instead of quietly testing something else.
 */
final class Vectors
{
    public const REQUEST_FILE = __DIR__ . '/../testdata/hmac_v1_vectors.json';
    public const REQUEST_SHA256 = 'a87df4921399dc14c7ceaa7e4c0dfa02495ad0400a5e722adfc0d3e3c1e064fe';

    public const WEBHOOK_FILE = __DIR__ . '/../testdata/webhook_hmac_v1_vectors.json';
    public const WEBHOOK_SHA256 = '15a6e1423708e8c3b9ec4fac7ee6eb383db56703605647e02308a29166722502';

    /**
     * @return list<array<string, mixed>>
     */
    public static function request(): array
    {
        return self::read(self::REQUEST_FILE, self::REQUEST_SHA256);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function webhook(): array
    {
        return self::read(self::WEBHOOK_FILE, self::WEBHOOK_SHA256);
    }

    public static function raw(string $file): string
    {
        $raw = file_get_contents($file);
        if (!is_string($raw)) {
            throw new \RuntimeException('cannot read ' . $file);
        }

        return $raw;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function read(string $file, string $sha256): array
    {
        $raw = self::raw($file);
        if (hash('sha256', $raw) !== $sha256) {
            throw new \RuntimeException($file . ' is not the reference copy: sha256 ' . hash('sha256', $raw));
        }
        /** @var list<array<string, mixed>> $records */
        $records = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $records;
    }
}
