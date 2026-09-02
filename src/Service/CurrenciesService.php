<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Service;

use CryptoChief\Processing\Dto\ConvertRequest;
use CryptoChief\Processing\Dto\ConvertResponse;
use CryptoChief\Processing\Dto\CryptoCurrencies;
use CryptoChief\Processing\Dto\FiatCurrency;

/**
 * Fiat <-> crypto rate calculator, plus the two catalogues of what can be quoted. Quotes
 * rates only; does NOT move funds, and there is no swap endpoint to move them with:
 * `autoConvert` on a payout is refused by the platform with `AUTO_CONVERT_NOT_IMPLEMENTED`.
 */
final class CurrenciesService extends BaseService
{
    /** Quote how much crypto the given fiat amount is worth. */
    public function fiatToCrypto(ConvertRequest $req): ConvertResponse
    {
        return self::fromWire(
            ConvertResponse::class,
            $this->post('/v1/currencies/convert/fiat-crypto', $req)
        );
    }

    /** Quote how much fiat the given crypto amount is worth. */
    public function cryptoToFiat(ConvertRequest $req): ConvertResponse
    {
        return self::fromWire(
            ConvertResponse::class,
            $this->post('/v1/currencies/convert/crypto-fiat', $req)
        );
    }

    /**
     * Every fiat currency the platform can price an order in and quote a rate against -
     * the codes to populate a currency selector with, and the values a FIAT-mode pay-in's
     * `currency` and the fiat side of {@see self::fiatToCrypto()} accept.
     *
     * The endpoint answers with a bare JSON array rather than an `items` envelope, which
     * is why this returns a list and not a response DTO.
     *
     * @return FiatCurrency[]
     */
    public function fiats(): array
    {
        return self::fromWireList(FiatCurrency::class, $this->post('/v1/currencies/fiats', []));
    }

    /**
     * Every crypto ticker the platform has a rate for, against USDT, and which exchange
     * each one comes from.
     *
     * Rate availability only: a ticker here can be quoted, which does NOT mean the
     * platform takes deposits, sweeps or payouts in it. For what your project can
     * actually be paid in use
     * {@see \CryptoChief\Processing\Service\BlockchainService::contractsAvailable()} - an
     * asset picker built from this list offers assets orders will refuse.
     */
    public function cryptos(): CryptoCurrencies
    {
        return self::fromWire(CryptoCurrencies::class, $this->post('/v1/currencies/cryptos', []));
    }
}
