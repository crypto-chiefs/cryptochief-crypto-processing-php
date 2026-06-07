<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Service;

use CryptoChief\Processing\Client;
use CryptoChief\Processing\Dto\BaseDto;

/**
 * Holds the client reference and a signed-POST helper. Request bodies are flattened to
 * their wire form via `BaseDto::toWire()` (camelCase -> snake_case, nulls dropped).
 */
abstract class BaseService
{
    public function __construct(protected readonly Client $client) {}

    /**
     * @param mixed $body
     * @return mixed
     */
    protected function post(string $path, mixed $body = null): mixed
    {
        return $this->client->request($path, self::toWire($body));
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    protected static function toWire(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof BaseDto) {
            return $value->toWire();
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                if ($v === null) {
                    continue;
                }
                $out[$k] = self::toWire($v);
            }
            return $out;
        }
        return $value;
    }

    /**
     * Convert a wire array into a DTO. Null / non-array input yields a zero-arg instance.
     *
     * @template T of BaseDto
     * @param class-string<T> $cls
     * @param mixed $data
     * @return T
     */
    protected static function fromWire(string $cls, mixed $data): BaseDto
    {
        if (!is_array($data)) {
            $data = [];
        }
        /** @var T $instance */
        $instance = $cls::fromWire($data);
        return $instance;
    }

    /**
     * @template T of BaseDto
     * @param class-string<T> $cls
     * @param mixed $data
     * @return T[]
     */
    protected static function fromWireList(string $cls, mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $row) {
            if (is_array($row)) {
                /** @var T $instance */
                $instance = $cls::fromWire($row);
                $out[] = $instance;
            }
        }
        return $out;
    }
}
