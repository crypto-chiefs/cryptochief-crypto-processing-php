<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * One fiat currency the platform can price an order in.
 *
 * The `code` is what a FIAT-mode {@see CreatePayInRequest::$currency} accepts and what
 * goes in the fiat side of a {@see ConvertRequest}.
 */
final class FiatCurrency extends BaseDto
{
    public function __construct(
        /** ISO 4217 code, e.g. `SEK`. */
        public readonly string $code = '',
        /** Display name, e.g. `Swedish Krona`. */
        public readonly string $name = '',
    ) {}
}
