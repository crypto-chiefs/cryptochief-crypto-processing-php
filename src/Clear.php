<?php

declare(strict_types=1);

namespace CryptoChief\Processing;

/**
 * "Stop overriding this field and inherit it again."
 *
 * Used with {@see Service\SweepsService::updateSettings()}, where the API expresses that
 * by naming a field and sending no value for it. `null` cannot say it, because in every
 * other argument of this SDK `null` already means "not supplied - leave it alone", and
 * the two are different instructions: one changes nothing, the other resets a value.
 *
 * Use {@see Clear::value()}; the constructor is private so there is only ever one.
 */
final class Clear
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function value(): self
    {
        return self::$instance ??= new self();
    }
}
