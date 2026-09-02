<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * Every crypto ticker the platform has a rate for, quoted against `$quote`.
 *
 * This is RATE availability, not PAYMENT availability. A ticker listed here is one the
 * platform can put a price on; it says nothing about whether your project can take a
 * deposit, sweep or pay out in it. That catalogue is
 * {@see \CryptoChief\Processing\Service\BlockchainService::contractsAvailable()}, and an
 * asset picker built from this type instead offers customers assets orders will refuse.
 */
final class CryptoCurrencies extends BaseDto
{
    /**
     * @param string[] $tickers The union of every exchange's list, deduplicated.
     * @param array<string, string[]> $byExchange The tickers each exchange carries, keyed
     *        by exchange name (`binance`, `bybit`, `exmo`, `kucoin`, ...). Which exchange
     *        a ticker comes from is what {@see ConvertRequest::$provider} selects.
     */
    public function __construct(
        public readonly array $tickers = [],
        public readonly array $byExchange = [],
        /** The asset the rates are quoted against - `USDT`. */
        public readonly string $quote = '',
        /** How many tickers `$tickers` holds, as the platform counted them. */
        public readonly int $count = 0,
    ) {}
}
